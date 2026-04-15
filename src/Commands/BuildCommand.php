<?php

/*
 * This file is part of blomstra/search.
 *
 * Copyright (c) 2022 Blomstra Ltd.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 *
 */

namespace Blomstra\Search\Commands;

use Blomstra\Search\Jobs\Job;
use Blomstra\Search\Jobs\UpdateSearchJob;
use Blomstra\Search\Seeders\Seeder;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\ElasticsearchException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Spatie\ElasticsearchQueryBuilder\Builder;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\RangeQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\TermQuery;

class BuildCommand extends Command
{
    /**
     * Bump this when a mapping change requires a full reindex.
     * Written into the mapping's _meta.index_compat_version on every build and
     * read back into blomstra-search.index-compatible by saveIndexedConfig so the
     * value always reflects the live index — including after a promote/rollback.
     */
    public const INDEX_COMPAT_VERSION = 'v2';

    /** Matches Flarum's Search::MIN_SEARCH_LEN — the default minimum query length. */
    public const DEFAULT_MIN_SEARCH_LENGTH = 3;

    protected $signature = 'blomstra:search:index
        {action? : build | promote | rollback | discard | mapping | fill}
        {--fresh}
        {--resume}
        {--staging}
        {--keep-backup}
        {--pending}
        {--backup}
        {--only=}
        {--max-id=}
        {--throttle=}
        {--i-am-sure}';

    protected $description = 'Build and manage the Elasticsearch search index.';

    protected $help = <<<'HELP'
<comment>Actions and their options:</comment>

  <info>build</info>                Queue documents and promote automatically.
    <comment>--resume</comment>         Resume an interrupted build from where it left off
    <comment>--fresh</comment>          Drop the staging index and start completely fresh
    <comment>--staging</comment>        Keep in staging — requires explicit <info>promote</info> (blue-green)
    <comment>--keep-backup</comment>    Retain the replaced index for rollback

  <info>promote</info>               Swap alias to staging index (blue-green workflow).
    <comment>--keep-backup</comment>    Retain the replaced index for rollback
    <comment>--i-am-sure</comment>      Skip the confirmation prompt

  <info>rollback</info>              Restore the backup index to live.

  <info>discard</info>               Drop an index without promoting.
    <comment>--pending</comment>        Drop the staging index (cancel a build)
    <comment>--backup</comment>         Drop the backup index (cleanup after --keep-backup)

  <info>mapping</info>               Push updated mapping to the live index only.

  <info>fill</info>                  Seed only documents missing from the live index.

<comment>Shared seeding options (build / fill):</comment>
  <comment>--only=TYPE</comment>       Seed only this type: <info>discussions</info> or <info>posts</info>
  <comment>--throttle=N</comment>      Seconds to wait between batches
  <comment>--max-id=N</comment>        Limit seeding to IDs up to this value
HELP;

    public function handle(Container $container): void
    {
        /** @var Client $client */
        $client = $container->make(Client::class);

        /** @var SettingsRepositoryInterface $settings */
        $settings = $container->make(SettingsRepositoryInterface::class);

        /** @var string $alias */
        $alias = $container->make('blomstra.search.elastic_index');

        if (!$this->argument('action')) {
            $this->call('help', ['command_name' => $this->getName()]);
            return;
        }

        switch ($this->argument('action')) {
            case 'build':
                $this->runBuild($client, $alias, $settings, $container);
                break;

            case 'promote':
                $this->runPromote($client, $alias, $settings);
                break;

            case 'rollback':
                $this->runRollback($client, $alias, $settings);
                break;

            case 'discard':
                $this->runDiscard($client, $settings);
                break;

            case 'mapping':
                $client->indices()->putMapping([
                    'index' => $alias,
                    'body'  => $this->mappingProperties(),
                ]);
                $this->info('Mapping updated on live index.');
                break;

            case 'fill':
                $this->runSeeders(
                    collect($container->tagged('blomstra.search.seeders')),
                    $container->make(Queue::class),
                    $client,
                    $settings,
                    $alias,
                    seedMissing: true
                );
                break;

            default:
                $this->error("Unknown action '{$this->argument('action')}'. Valid actions: build, promote, rollback, discard, mapping, fill.");
        }
    }

    protected function runBuild(Client $client, string $alias, SettingsRepositoryInterface $settings, Container $container): void
    {
        /** @var Seeder[] $seeders */
        $seeders = collect($container->tagged('blomstra.search.seeders'));

        // Guard: staging build exists without explicit intent given.
        $staging = $settings->get('blomstra-search.staging-index');
        if ($staging && $client->indices()->exists(['index' => $staging])
            && !$this->option('resume') && !$this->option('fresh')
        ) {
            $this->warn("An in-progress build already exists: $staging");
            $this->line('');
            $this->line('Choose one of:');
            $this->line('  blomstra:search:index build --resume            Continue from where it left off');
            $this->line('  blomstra:search:index build --resume --staging   Continue and keep in staging when done');
            $this->line('  blomstra:search:index build --fresh              Drop this build and start completely fresh');
            $this->line('  blomstra:search:index build --fresh --staging    Start fresh and keep in staging when done');
            return;
        }

        $aliasExists = (bool) $client->indices()->existsAlias(['name' => $alias]);
        $indexExists = !$aliasExists && (bool) $client->indices()->exists(['index' => $alias]);

        if (!$aliasExists && !$indexExists) {
            // First install: create a timestamped index, alias it immediately, seed live.
            $targetIndex  = $this->prepareFirstInstall($client, $alias, $settings, $seeders);
            $stagingBuild = false;
        } else {
            // Live system: build into a staging index without touching the live alias.
            $targetIndex  = $this->prepareStagingIndex($client, $alias, $settings, $seeders);
            $stagingBuild = true;
        }

        $client->indices()->putMapping([
            'index' => $targetIndex,
            'body'  => $this->mappingProperties(),
        ]);

        $this->runSeeders($seeders, $container->make(Queue::class), $client, $settings, $targetIndex);

        if ($stagingBuild) {
            if ($this->option('staging')) {
                $staging = $settings->get('blomstra-search.staging-index');
                $this->info("Staging build complete. Drain the queue, then promote '$staging' to live:");
                $this->line('  php flarum queue:work --stop-when-empty');
                $this->line('  php flarum blomstra:search:index promote');
            } else {
                $this->info('Jobs queued. Promoting now (search improves as the queue drains)...');
                $this->performPromote($client, $alias, $settings);
            }
        }
    }

    protected function runPromote(Client $client, string $alias, SettingsRepositoryInterface $settings): void
    {
        if (!$this->option('i-am-sure')) {
            $this->warn('promote switches the live index. Ensure all queued jobs have finished first:');
            $this->line('  php flarum queue:work --stop-when-empty');
            $this->line('');

            if (!$this->confirm('Queue drained? Proceed?')) {
                return;
            }
        }

        $this->performPromote($client, $alias, $settings);
    }

    protected function performPromote(Client $client, string $alias, SettingsRepositoryInterface $settings): void
    {
        $staging = $settings->get('blomstra-search.staging-index');

        if (!$staging || !$client->indices()->exists(['index' => $staging])) {
            $this->error('No staging index ready to promote. Run: blomstra:search:index build');
            return;
        }

        $aliasExists = (bool) $client->indices()->existsAlias(['name' => $alias]);
        $indexExists = !$aliasExists && (bool) $client->indices()->exists(['index' => $alias]);

        if ($aliasExists) {
            $result      = $client->indices()->getAlias(['name' => $alias]);
            $activeIndex = array_key_first($result);

            $client->indices()->updateAliases([
                'body' => ['actions' => [
                    ['remove' => ['index' => $activeIndex, 'alias' => $alias]],
                    ['add'    => ['index' => $staging,     'alias' => $alias]],
                ]],
            ]);

            $this->info("'$alias' now points to '$staging'. Promote complete.");

            if ($this->option('keep-backup')) {
                $settings->set('blomstra-search.backup-index', $activeIndex);
                $this->info("Kept '$activeIndex' as backup — run rollback to revert, discard --backup to drop it.");
            } else {
                $client->indices()->delete(['index' => $activeIndex, 'ignore_unavailable' => true]);
                $this->info("Deleted old index: $activeIndex");
            }
        } else {
            // One-time migration: concrete index → alias (legacy installs only).
            if ($indexExists) {
                $client->indices()->delete(['index' => $alias]);
                $this->info("Deleted legacy concrete index: $alias");
            }

            $client->indices()->putAlias(['index' => $staging, 'name' => $alias]);
            $this->info("'$alias' now points to '$staging'. Promote complete.");
        }

        $settings->set('blomstra-search.active-index', $staging);
        $settings->set('blomstra-search.staging-index', null);
        $this->saveIndexedConfig($client, $settings, $staging);
    }

    protected function runRollback(Client $client, string $alias, SettingsRepositoryInterface $settings): void
    {
        $backup = $settings->get('blomstra-search.backup-index');

        if (!$backup || !$client->indices()->exists(['index' => $backup])) {
            $this->error('No backup index to roll back to. Was promote run with --keep-backup?');
            return;
        }

        $result      = $client->indices()->getAlias(['name' => $alias]);
        $activeIndex = array_key_first($result);

        $client->indices()->updateAliases([
            'body' => ['actions' => [
                ['remove' => ['index' => $activeIndex, 'alias' => $alias]],
                ['add'    => ['index' => $backup,      'alias' => $alias]],
            ]],
        ]);

        $client->indices()->delete(['index' => $activeIndex, 'ignore_unavailable' => true]);
        $this->info("Rolled back: '$alias' now points to '$backup'. Deleted '$activeIndex'.");

        $settings->set('blomstra-search.active-index', $backup);
        $settings->set('blomstra-search.backup-index', null);
        $this->saveIndexedConfig($client, $settings, $backup);
    }

    protected function runDiscard(Client $client, SettingsRepositoryInterface $settings): void
    {
        $pending = $this->option('pending');
        $backup  = $this->option('backup');

        if (!$pending && !$backup) {
            $this->error('Specify what to discard: --pending (staging build) or --backup (rollback index).');
            return;
        }

        if ($pending) {
            $staging = $settings->get('blomstra-search.staging-index');

            if (!$staging) {
                $this->warn('No staging index to discard.');
            } else {
                if ($client->indices()->exists(['index' => $staging])) {
                    $client->indices()->delete(['index' => $staging]);
                }
                $settings->set('blomstra-search.staging-index', null);
                $this->info("Discarded staging index: $staging");
            }
        }

        if ($backup) {
            $backupIndex = $settings->get('blomstra-search.backup-index');

            if (!$backupIndex) {
                $this->warn('No backup index to discard.');
            } else {
                if ($client->indices()->exists(['index' => $backupIndex])) {
                    $client->indices()->delete(['index' => $backupIndex]);
                }
                $settings->set('blomstra-search.backup-index', null);
                $this->info("Discarded backup index: $backupIndex");
            }
        }
    }

    protected function runSeeders(
        iterable $seeders,
        Queue $queue,
        Client $client,
        SettingsRepositoryInterface $settings,
        string $targetIndex,
        bool $seedMissing = false
    ): void {
        $only = $this->option('only');

        /** @var Seeder $seeder */
        foreach ($seeders as $seeder) {
            if ($only && $seeder->type() !== $only) {
                continue;
            }

            $total = 0;

            if ($this->option('resume')) {
                $saved = $this->getContinueAt($settings, $seeder->type());

                if ($saved === 0) {
                    $this->info("Seeder '{$seeder->type()}' already completed in a previous run, skipping.");
                    continue;
                }

                $continueAt = $saved ?? $seeder->query()->max('id');
            } else {
                $continueAt = $seeder->query()->max('id');
            }

            $seeded = null;

            while ($continueAt !== null) {
                $rangeFrom = max(1, $continueAt - 2500);
                $rangeTo   = $continueAt;

                if ($seedMissing) {
                    $seeded = $this->queryIndexedIds($client, $targetIndex, $seeder->joinRelation(), $rangeFrom, $rangeTo);
                }

                /** @var Collection $collection */
                $collection = $seeder->query()
                    ->latest('id')
                    ->whereBetween('id', [$rangeFrom, $rangeTo])
                    ->when($this->option('max-id'), fn ($q, $id) => $q->where('id', '<=', $id))
                    ->when($seeded, fn ($q, $seeded) => $q->whereNotIn('id', $seeded))
                    ->get();

                $min = $collection->min('id');

                if ($seedMissing && $collection->isEmpty()) {
                    $continueAt = $rangeFrom > 2 ? $rangeFrom - 1 : null;
                } else {
                    $continueAt = $min && $min > 2 ? $min - 1 : null;
                }

                if ($collection->isNotEmpty()) {
                    $queue->pushOn(Job::$onQueue, new UpdateSearchJob($collection, $seeder, $targetIndex));
                }

                $this->info("IDs {$rangeFrom}–{$rangeTo} | type: {$seeder->type()} | queued: {$collection->count()}.");

                $total += $collection->count();
                $this->setContinueAt($settings, $seeder->type(), $continueAt);

                if ($throttle = $this->option('throttle')) {
                    $this->info("Throttling for $throttle seconds");
                    sleep($throttle);
                }
            }

            $this->setContinueAt($settings, $seeder->type(), 0);
            $this->info("Queued a total of $total {$seeder->type()} for indexing.");
        }
    }

    /**
     * First install: create a timestamped concrete index, alias the configured name to it
     * immediately, and return the alias so seeding goes through it and is live from the start.
     */
    protected function prepareFirstInstall(
        Client $client,
        string $alias,
        SettingsRepositoryInterface $settings,
        iterable $seeders
    ): string {
        $concrete = $alias . '_' . date('YmdHis');

        $client->indices()->create([
            'index' => $concrete,
            'body'  => ['settings' => $this->buildIndexSettings($settings)],
        ]);

        $client->indices()->putAlias(['index' => $concrete, 'name' => $alias]);

        $settings->set('blomstra-search.active-index', $concrete);
        $this->saveIndexedConfig($client, $settings, $concrete);

        foreach ($seeders as $seeder) {
            $this->setContinueAt($settings, $seeder->type(), null);
        }

        $this->info("Created '$concrete', aliased '$alias' → '$concrete'.");
        $this->info("Index is live — documents become searchable as the queue processes.");

        return $alias;
    }

    /**
     * Prepare the staging index for a blue-green build.
     *
     * - If a staging build exists and --fresh is not set, resume it.
     * - Otherwise create a fresh timestamped index and save it as staging.
     */
    protected function prepareStagingIndex(
        Client $client,
        string $alias,
        SettingsRepositoryInterface $settings,
        iterable $seeders
    ): string {
        $staging = $settings->get('blomstra-search.staging-index');

        if ($this->option('fresh') && $staging) {
            if ($client->indices()->exists(['index' => $staging])) {
                $client->indices()->delete(['index' => $staging]);
                $this->info("Dropped staging index: $staging");
            }
            $staging = null;
            $settings->set('blomstra-search.staging-index', null);
        }

        if ($staging && $client->indices()->exists(['index' => $staging])) {
            $this->info("Resuming staging index build: $staging");
            return $staging;
        }

        $staging = $alias . '_' . date('YmdHis');

        $client->indices()->create([
            'index' => $staging,
            'body'  => ['settings' => $this->buildIndexSettings($settings)],
        ]);

        $settings->set('blomstra-search.staging-index', $staging);

        foreach ($seeders as $seeder) {
            $this->setContinueAt($settings, $seeder->type(), null);
        }

        $this->info("Created staging index: $staging");

        return $staging;
    }

    /**
     * Persist the analysis config and compat version that are actually live in ES for the
     * given index. Reading from ES (rather than from Flarum settings) means rollbacks are
     * also covered: the stored values always reflect the index that is currently aliased,
     * not the settings at the time the command ran. Indexes built before _meta tracking
     * existed will yield a null compat version, which correctly triggers the reindex warning.
     */
    protected function saveIndexedConfig(Client $client, SettingsRepositoryInterface $settings, string $indexName): void
    {
        $settingsResponse = $client->indices()->getSettings(['index' => $indexName]);
        $analysis         = Arr::get($settingsResponse, "$indexName.settings.index.analysis", []);

        $analyzer = Arr::get($analysis, 'analyzer.flarum_analyzer.type', 'english');
        $minGram  = (int) Arr::get($analysis, 'filter.partial_search_filter.min_gram', self::DEFAULT_MIN_SEARCH_LENGTH);

        $mappingResponse = $client->indices()->getMapping(['index' => $indexName]);
        $compatVersion   = Arr::get($mappingResponse, "$indexName.mappings._meta.index_compat_version");

        $settings->set('blomstra-search.indexed-analyzer', $analyzer);
        $settings->set('blomstra-search.indexed-min-search-length', $minGram);
        $settings->set('blomstra-search.index-compatible', $compatVersion);
    }

    protected function buildIndexSettings(SettingsRepositoryInterface $settings): array
    {
        $language = $settings->get('blomstra-search.analyzer-language') ?: 'english';
        $minGram  = max(1, (int) ($settings->get('blomstra-search.min-search-length') ?: self::DEFAULT_MIN_SEARCH_LENGTH));
        $maxGram  = 10;

        if ($minGram >= $maxGram) {
            $this->error("min_gram ($minGram) must be less than max_gram ($maxGram). Using default.");
            $minGram = self::DEFAULT_MIN_SEARCH_LENGTH;
        }

        return [
            'index.max_ngram_diff' => $maxGram - $minGram,
            'analysis'             => [
                'analyzer' => [
                    'flarum_analyzer' => [
                        'type' => $language,
                    ],
                    'flarum_analyzer_partial' => [
                        'type'      => 'custom',
                        'tokenizer' => 'standard',
                        'filter'    => ['lowercase', 'partial_search_filter'],
                    ],
                ],
                'filter' => [
                    'partial_search_filter' => [
                        'type'        => 'ngram',
                        'min_gram'    => $minGram,
                        'max_gram'    => $maxGram,
                        'token_chars' => ['letter', 'digit', 'symbol'],
                    ],
                ],
            ],
        ];
    }

    protected function mappingProperties(): array
    {
        return [
            '_meta'      => ['index_compat_version' => self::INDEX_COMPAT_VERSION],
            'properties' => [
                'join_field'       => ['type' => 'join', 'relations' => ['discussion' => 'post']],
                'discussion_id'    => ['type' => 'integer'],
                'content'          => ['type' => 'text', 'analyzer' => 'flarum_analyzer_partial', 'search_analyzer' => 'flarum_analyzer'],
                'rawId'            => ['type' => 'integer'],
                'created_at'       => ['type' => 'date'],
                'updated_at'       => ['type' => 'date'],
                'is_private'       => ['type' => 'boolean'],
                'is_sticky'        => ['type' => 'boolean'],
                'groups'           => ['type' => 'integer'],
                'tags'             => ['type' => 'integer'],
                'recipient_groups' => ['type' => 'integer'],
                'recipient_users'  => ['type' => 'integer'],
                'comment_count'    => ['type' => 'integer'],
                'view_count'       => ['type' => 'integer'],
                'is_hidden'        => ['type' => 'boolean'],
            ],
        ];
    }

    /**
     * Query ES for rawIds already indexed in $targetIndex for the given $joinRelation and ID range.
     * Retries on transient ES failures (NoNodesAvailableException / other ElasticsearchException)
     * by sleeping past the StaticNoPingConnectionPool dead-node timeout (default 60 s) before
     * each retry, giving the pool a chance to resurface the node.
     */
    protected function queryIndexedIds(
        Client $client,
        string $targetIndex,
        string $joinRelation,
        int $rangeFrom,
        int $rangeTo,
        int $maxRetries = 10
    ): array {
        $attempt = 0;

        while (true) {
            try {
                $response = (new Builder($client))
                    ->index($targetIndex)
                    ->size(2500)
                    ->addQuery(
                        (new BoolQuery())
                            ->add((new RangeQuery('rawId'))->gte($rangeFrom)->lte($rangeTo))
                            ->add(TermQuery::create('join_field', $joinRelation))
                    )
                    ->search();

                return Arr::pluck(Arr::get($response, 'hits.hits'), '_source.rawId');
            } catch (ElasticsearchException $e) {
                $attempt++;

                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                $this->warn("ES error on range {$rangeFrom}–{$rangeTo} (attempt {$attempt}/{$maxRetries}): {$e->getMessage()}. Waiting 65 s before retry…");
                sleep(65); // outlast the StaticNoPingConnectionPool dead-node window (default 60 s)
            }
        }
    }

    protected function getContinueAt(SettingsRepositoryInterface $settings, string $type): ?int
    {
        $raw = $settings->get("blomstra-search.continued-at.$type");

        return $raw !== null ? (int) $raw : null;
    }

    protected function setContinueAt(SettingsRepositoryInterface $settings, string $type, ?int $at): void
    {
        $settings->set("blomstra-search.continued-at.$type", $at);
    }
}
