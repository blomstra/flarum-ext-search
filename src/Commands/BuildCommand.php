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
    protected $signature = 'blomstra:search:index
        {--max-id= : Limits for each object the number of items to seed}
        {--throttle= : Number of seconds to wait between pushing to the queue}
        {--only= : type to run seeder for, eg discussions or posts}
        {--recreate : Build into a new timestamped pending index (never touches the live alias)}
        {--discard : With --recreate, discard any in-progress pending build and start completely fresh}
        {--build-only : Deprecated no-op; --recreate no longer auto-swaps (kept for scripting compatibility)}
        {--mapping : Push updated mapping to the active index without rebuilding}
        {--continue : Resume each seeder from where it left off (use with --recreate when resuming an interrupted build)}
        {--seed-missing : Seed only documents missing from the active index}
        {--swap : Atomically swap the alias from the active index to the completed pending index}
        {--i-am-sure : Required with --swap; confirms the queue is fully drained before promoting}';

    protected $description = 'Rebuilds the complete search index.';

    public function handle(Container $container): void
    {
        /** @var Client $client */
        $client = $container->make(Client::class);

        /** @var SettingsRepositoryInterface $settings */
        $settings = $container->make(SettingsRepositoryInterface::class);

        /** @var string $alias */
        $alias = $container->make('blomstra.search.elastic_index');

        // --swap: promote the pending index to live, nothing else.
        if ($this->option('swap')) {
            if (!$this->option('i-am-sure')) {
                $this->warn('--swap promotes the pending index to live. This cannot be undone.');
                $this->line('');
                $this->line('Before swapping, ensure the queue is fully drained:');
                $this->line('  php flarum queue:work --stop-when-empty');
                $this->line('');

                if (!$this->confirm('Has the queue been drained? Proceed with the swap?')) {
                    return;
                }
            }

            $this->performSwap($client, $alias, $settings);
            return;
        }

        /** @var Queue $queue */
        $queue = $container->make(Queue::class);

        /** @var Seeder[] $seeders */
        $seeders = collect($container->tagged('blomstra.search.seeders'));

        // Determine which concrete index to write into.
        if ($this->option('recreate')) {
            // If a pending build exists, require an explicit choice before touching anything.
            $pending = $settings->get('blomstra-search.pending-index');
            if ($pending && $client->indices()->exists(['index' => $pending])
                && !$this->option('continue') && !$this->option('discard')
            ) {
                $this->warn("An in-progress build already exists: $pending");
                $this->line('');
                $this->line('Choose one of:');
                $this->line('  --recreate --continue   Resume each seeder from where it left off');
                $this->line('  --recreate --discard    Discard this build and start completely fresh');
                return;
            }

            $targetIndex = $this->preparePendingIndex($client, $alias, $settings, $seeders);
        } else {
            // --mapping / --seed-missing / normal: write directly through the alias.
            $targetIndex = $alias;
        }

        // Apply or update the mapping.
        if ($this->option('recreate') || $this->option('mapping')) {
            $client->indices()->putMapping([
                'index' => $targetIndex,
                'body'  => $this->mappingProperties(),
            ]);
        }

        // Run seeders into $targetIndex.
        $only = $this->option('only');

        /** @var Seeder $seeder */
        foreach ($seeders as $seeder) {
            if ($only && $seeder->type() !== $only) {
                continue;
            }

            $total = 0;

            $continueAt = $this->option('continue')
                ? ($this->getContinueAt($settings, $seeder->type()) ?? $seeder->query()->max('id'))
                : $seeder->query()->max('id');

            $seeded = null;

            while ($continueAt !== null) {
                $rangeFrom = max(1, $continueAt - 1000);
                $rangeTo   = $continueAt;

                if ($this->option('seed-missing')) {
                    $response = (new Builder($client))
                        ->index($targetIndex)
                        ->size(1000)
                        ->addQuery(
                            (new BoolQuery())
                                ->add((new RangeQuery('rawId'))->gte($rangeFrom)->lte($rangeTo))
                                ->add(TermQuery::create('join_field', $seeder->joinRelation()))
                        )
                        ->search();

                    $seeded = Arr::pluck(Arr::get($response, 'hits.hits'), '_source.rawId');
                }

                /** @var Collection $collection */
                $collection = $seeder->query()
                    ->latest('id')
                    ->whereBetween('id', [$rangeFrom, $rangeTo])
                    ->when($this->option('max-id'), fn ($q, $id) => $q->where('id', '<=', $id))
                    ->when($seeded, fn ($q, $seeded) => $q->whereNotIn('id', $seeded))
                    ->get();

                $min = $collection->min('id');

                if ($this->option('seed-missing') && $collection->isEmpty()) {
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

            $this->info("Queued a total of $total {$seeder->type()} for indexing.");
        }

        if ($this->option('recreate')) {
            $this->info("Build complete. Drain the queue, then promote '$targetIndex' to live:");
            $this->line("  php flarum queue:work --stop-when-empty");
            $this->line("  php flarum blomstra:search:index --swap");
        }
    }

    /**
     * Prepare the concrete index that the current build will write into.
     *
     * - If a pending build exists and --discard is not set, resume it.
     * - Otherwise create a fresh timestamped index and save it as pending.
     */
    protected function preparePendingIndex(
        Client $client,
        string $alias,
        SettingsRepositoryInterface $settings,
        iterable $seeders
    ): string {
        $pending = $settings->get('blomstra-search.pending-index');

        // --discard: throw away any in-progress build.
        if ($this->option('discard') && $pending) {
            if ($client->indices()->exists(['index' => $pending])) {
                $client->indices()->delete(['index' => $pending]);
                $this->info("Discarded pending index: $pending");
            }
            $pending = null;
            $settings->set('blomstra-search.pending-index', null);
        }

        // Resume an existing pending build (only reached when --continue or --discard was specified).
        if ($pending && $client->indices()->exists(['index' => $pending])) {
            $this->info("Resuming pending index build: $pending");
            return $pending;
        }

        // Fresh build: create a new timestamped concrete index.
        $pending = $alias . '_' . date('YmdHis');

        $client->indices()->create([
            'index' => $pending,
            'body'  => ['settings' => $this->indexSettings($settings)],
        ]);

        $settings->set('blomstra-search.pending-index', $pending);

        // Clear per-seeder progress so the fresh build starts from the top.
        foreach ($seeders as $seeder) {
            $this->setContinueAt($settings, $seeder->type(), null);
        }

        $this->info("Created pending index: $pending");

        return $pending;
    }

    /**
     * Atomically promote the pending index to live by swapping the alias.
     *
     * Handles both the normal alias-swap case and the one-time migration from
     * an older installation where the configured name was a concrete index.
     */
    protected function performSwap(Client $client, string $alias, SettingsRepositoryInterface $settings): void
    {
        $pending = $settings->get('blomstra-search.pending-index');

        if (!$pending || !$client->indices()->exists(['index' => $pending])) {
            $this->error('No pending index found. Run --recreate [--build-only] first.');
            return;
        }

        $aliasExists = (bool) $client->indices()->existsAlias(['name' => $alias]);
        $indexExists = !$aliasExists && (bool) $client->indices()->exists(['index' => $alias]);

        if ($aliasExists) {
            // Normal case: atomic remove-old / add-new in a single API call.
            $result   = $client->indices()->getAlias(['name' => $alias]);
            $oldIndex = array_key_first($result);

            $client->indices()->updateAliases([
                'body' => ['actions' => [
                    ['remove' => ['index' => $oldIndex, 'alias' => $alias]],
                    ['add'    => ['index' => $pending,  'alias' => $alias]],
                ]],
            ]);

            $this->info("Alias '$alias' → '$pending'. Swap complete.");
            $client->indices()->delete(['index' => $oldIndex, 'ignore_unavailable' => true]);
            $this->info("Deleted old index: $oldIndex");
        } else {
            // One-time migration: the configured name was a concrete index (old install).
            // Brief downtime window here is acceptable — it only happens once.
            if ($indexExists) {
                $client->indices()->delete(['index' => $alias]);
                $this->info("Deleted legacy concrete index: $alias");
            }

            $client->indices()->putAlias(['index' => $pending, 'name' => $alias]);
        }

        $settings->set('blomstra-search.active-index', $pending);
        $settings->set('blomstra-search.pending-index', null);

        $this->info("Alias '$alias' → '$pending'. Swap complete.");
    }

    protected function indexSettings(SettingsRepositoryInterface $settings): array
    {
        return [
            'index.max_ngram_diff' => 7,
            'analysis'             => [
                'analyzer' => [
                    'flarum_analyzer' => [
                        'type' => $settings->get('blomstra-search.analyzer-language') ?: 'english',
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
                        'min_gram'    => 3,
                        'max_gram'    => 10,
                        'token_chars' => ['letter', 'digit', 'symbol'],
                    ],
                ],
            ],
        ];
    }

    protected function mappingProperties(): array
    {
        return [
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
