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
use Blomstra\Search\Jobs\SavingJob;
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
        {--recreate : create or recreate the index}
        {--mapping : recreate the mapping}
        {--continue : continue each object type where you left off}
        {--seed-missing : attempt to seed only objects that are missing in the index}';

    protected $description = 'Rebuilds the complete search server with its documents.';

    public function handle(Container $container)
    {
        /** @var string $index */
        $index = $container->make('blomstra.search.elastic_index');

        /** @var array|string[] $seeders */
        $seeders = $container->tagged('blomstra.search.seeders');

        /** @var Queue $queue */
        $queue = $container->make(Queue::class);

        /** @var Client $client */
        $client = $container->make(Client::class);

        /** @var SettingsRepositoryInterface $settings */
        $settings = $container->make(SettingsRepositoryInterface::class);

        // Elastic scheme definition.
        $properties = [
            'properties' => [
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
            ],
        ];

        // Remove/delete the whole index.
        if ($this->option('recreate')) {
            // Flush the index.
            $client->indices()->delete([
                'index'              => $index,
                'ignore_unavailable' => true,
            ]);

            // Create a new index.
            $client->indices()->create([
                'index' => $index,
                'body'  => [
                    'settings' => [
                        'index.max_ngram_diff' => 10,
                        'analysis'             => [
                            'analyzer' => [
                                'flarum_analyzer' => [
                                    'type' => $settings->get('blomstra-search.analyzer-language') ?: 'english',
                                ],
                                'flarum_analyzer_partial' => [
                                    'type'      => 'custom',
                                    'tokenizer' => 'standard',
                                    'filter'    => [
                                        'lowercase',
                                        'partial_search_filter',
                                    ],
                                ],
                            ],
                            'filter' => [
                                'partial_search_filter' => [
                                    'type'        => 'ngram',
                                    'min_gram'    => 1,
                                    'max_gram'    => 10,
                                    'token_chars' => ['letter', 'digit', 'symbol'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        // Create the index.
        if ($this->option('recreate') || $this->option('mapping')) {
            $client->indices()->putMapping([
                'index' => $index,
                'body'  => $properties,
            ]);
        }

        // Seed only a specific resource.
        $only = $this->option('only');

        /** @var Seeder $seeder */
        foreach ($seeders as $seeder) {
            if ($only && $seeder->type() !== $only) {
                continue;
            }

            $total = 0;

            $continueAt = $this->option('continue')
                ? ($this->continueAt($seeder->type()) ?? $seeder->query()->max('id'))
                : $seeder->query()->max('id');

            $seeded = null;

            while ($continueAt !== null) {
                if ($this->option('seed-missing')) {
                    $response = (new Builder($client))
                        ->index($index)
                        ->size(1000)
                        ->addQuery(
                            (new BoolQuery())
                            ->add((new RangeQuery('rawId'))
                                ->gte($continueAt - 1000)
                                ->lte($continueAt))
                            ->add(TermQuery::create('type', $seeder->type()))
                        )
                        ->search();

                    $seeded = Arr::pluck(Arr::get($response, 'hits.hits'), '_source.rawId');
                }

                /** @var Collection $collection */
                $collection = $seeder->query()
                    ->latest('id')
                    ->whereBetween('id', [$continueAt - 1000, $continueAt])
                    ->when($this->option('max-id'), function ($query, $id) {
                        $query->where('id', '<=', $id);
                    })
                    ->when($seeded, fn ($query, $seeded) => $query->whereNotIn('id', $seeded))
                    ->get();

                $min = $collection->min('id');
                $continueAt = $min && $min > 2 ? $min - 1 : null;

                $queue->pushOn(Job::$onQueue, new SavingJob($collection, $seeder));

                $this->info("Pushed into the index, type: {$seeder->type()}, amount: {$collection->count()}.");

                $total += $collection->count();

                $this->continueAt(
                    $seeder->type(),
                    $continueAt
                );

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
