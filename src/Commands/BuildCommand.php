<?php

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

class BuildCommand extends Command
{
    protected $signature = 'blomstra:search:index
        {--max-id= : Limits for each object the number of items to seed}
        {--chunk-size= : Size of the chunks to dispatch into jobs}
        {--throttle= : Number of seconds to wait between pushing to the queue}';
    protected $description = 'Rebuilds the complete search server with its documents.';

    public function handle(Container $container)
    {
        $index = $container->make('blomstra.search.elastic_index');

        /** @var array $seeders */
        $seeders = $container->tagged('blomstra.search.seeders');

        /** @var Queue $queue */
        $queue = $container->make(Queue::class);

        /** @var Client $client */
        $client = $container->make(Client::class);

        /** @var SettingsRepositoryInterface $settings */
        $settings = $container->make(SettingsRepositoryInterface::class);

        $properties = [
            'properties' => [
                'content' => ['type' => 'text', 'analyzer' => 'flarum_analyzer'],
                'created_at' => ['type' => 'date'],
                'updated_at' => ['type' => 'date'],
                'is_private' => ['type' => 'boolean'],
                'is_sticky' => ['type' => 'boolean'],
                'groups' => ['type' => 'integer'],
                'recipient_groups' => ['type' => 'integer'],
                'recipient_users' => ['type' => 'integer'],
            ]
        ];

        // Flush the index.
        $client->indices()->delete([
            'index' => $index,
            'ignore_unavailable' => true
        ]);

        // Create a new index.
        $client->indices()->create([
            'index' => $index,
            'body' => [
                'settings' => [
                    'analysis' => [
                        'analyzer' => [
                            'flarum_analyzer' => [
                                'type' => $settings->get('blomstra-search.analyzer-language') ?: 'english'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $client->indices()->putMapping([
            'index' => $index,
            'body' => $properties
        ]);

        /** @var Seeder $seeder */
        foreach ($seeders as $seeder) {
            $seeder->query()
                ->when($this->option('max-id'), function ($query, $id) {
                    $query->where('id', '<=', $id);
                })
                ->latest('created_at')
                ->chunk($this->option('chunk-size') ?? 1000, function (Collection $collection) use ($queue, &$total, $seeder) {
                    $queue->pushOn(Job::$onQueue, new SavingJob($collection, $seeder));

                    $this->info("Pushed into the index, type: {$seeder->type()}, amount: {$collection->count()}.");

                    $total += $collection->count();
                });

            $this->info("Pushed a total of $total into the index.");

            if ($throttle = $this->option('throttle')) {
                $this->info("Throttling for $throttle seconds");
                sleep($throttle);
            }
        }
    }
}
