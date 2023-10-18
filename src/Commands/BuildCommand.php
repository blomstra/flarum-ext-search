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

use Blomstra\Search\Discussion\DiscussionIndexer;
use Blomstra\Search\Elasticsearch\Builder;
use Blomstra\Search\Post\CommentPostIndexer;
use Elasticsearch\Client;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\Job\IndexJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\RangeQuery;

class BuildCommand extends Command
{
    protected $signature = 'blomstra:search:index
        {--max-id= : Limits for each object the number of items to seed}
        {--throttle= : Number of seconds to wait between pushing to the queue}
        {--only= : type of model to index, eg discussions or posts}
        {--recreate : create or recreate the index}
        {--mapping : recreate the mapping}
        {--continue : continue each object type where you left off}
        {--seed-missing : attempt to seed only objects that are missing in the index}';

    protected $description = 'Rebuilds the complete search server with its documents.';

    public function handle(Container $container, Client $client, Queue $queue, SettingsRepositoryInterface $settings)
    {
        $indexers = [
            'discussions' => DiscussionIndexer::class,
            'posts'       => CommentPostIndexer::class,
        ];
        $models = [
            'discussions' => Discussion::class,
            'posts'       => Post::class,
        ];

        $only = $this->option('only');
        $onlyIndexers = $only
            ? Arr::only($indexers, is_array($only) ? $only : explode(',', $only))
            : $indexers;

        foreach ($onlyIndexers as $type => $indexerClass) {
            /** @var DiscussionIndexer|CommentPostIndexer $indexer */
            $indexer = $container->make($indexerClass);

            $properties = [
                'properties' => $indexer->properties(),
            ];

            // Remove/delete the whole index.
            if ($this->option('recreate')) {
                $indexer->flush();
                $indexer->build();
            }

            // Create the index.
            if (!$this->option('recreate') && $this->option('mapping')) {
                $client->indices()->putMapping([
                    'index' => $indexer::index(),
                    'body'  => $properties,
                ]);
            }

            $total = 0;

            $query = $models[$type]::query();

            $continueAt = $this->option('continue')
                ? ($this->continueAt($type) ?? $query->max('id'))
                : $query->max('id');

            $seeded = null;

            while ($continueAt !== null) {
                if ($this->option('seed-missing')) {
                    $response = (new Builder($client))
                        ->index($indexer::index())
                        ->size(1000)
                        ->addQuery(
                            (new BoolQuery())
                                ->add((new RangeQuery('rawId'))
                                ->gte($continueAt - 1000)
                                ->lte($continueAt))
                        )
                        ->search();

                    $seeded = Arr::pluck(Arr::get($response, 'hits.hits'), '_source.rawId');
                }

                /** @var Collection $collection */
                $collection = $query
                    ->latest('id')
                    ->whereBetween('id', [$continueAt - 1000, $continueAt])
                    ->when($this->option('max-id'), function ($query, $id) {
                        $query->where('id', '<=', $id);
                    })
                    ->when($seeded, fn ($query, $seeded) => $query->whereNotIn('id', $seeded))
                    ->get();

                $min = $collection->min('id');
                $continueAt = $min && $min > 2 ? $min - 1 : null;

                $onQueue = property_exists($indexerClass, 'queue') ? $indexerClass::$queue : null;
                $queue->pushOn($onQueue, new IndexJob($indexerClass, $collection->all(), IndexJob::SAVE));

                $this->info("Pushed into the index, type: $type, amount: {$collection->count()}.");

                $total += $collection->count();

                $this->continueAt($type, $continueAt);

                if ($throttle = $this->option('throttle')) {
                    $this->info("Throttling for $throttle seconds");
                    sleep($throttle);
                }
            }

            $this->info("Pushed a total of $total into the index.");
        }
    }

    protected function continueAt(string $type, int $at = null)
    {
        /** @var SettingsRepositoryInterface $settings */
        $settings = resolve(SettingsRepositoryInterface::class);

        $key = "blomstra-search.continued-at.$type";

        if ($at) {
            $settings->set($key, $at);
        } else {
            return $settings->get($key);
        }
    }
}
