<?php

namespace Blomstra\Search\Commands;

use Blomstra\Search\Jobs\Job;
use Blomstra\Search\Jobs\SavingJob;
use Blomstra\Search\Seeders\Seeder;
use Carbon\Carbon;
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
        {--throttle= : Number of seconds to wait between pushing to the queue}
        {--only= : type to run seeder for, eg discussions or posts}
        {--recreate : create or recreate the index}
        {--continue : continue each object type where you left off}';
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
                'comment_count' => ['type' => 'integer'],
            ]
        ];

        if ($this->option('recreate')) {
            // Flush the index.
            $client->indices()->delete([
                'index'              => $index,
                'ignore_unavailable' => true
            ]);

            // Create a new index.
            $client->indices()->create([
                'index' => $index,
                'body'  => [
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
                'body'  => $properties
            ]);
        }

        $only = $this->option('only');

        /** @var Seeder $seeder */
        foreach ($seeders as $seeder) {
            if ($only && $seeder->type() !== $only) continue;

            $total = 0;

            $continueAt = $this->option('continue')
                ? ($this->continueAt($seeder->type()) ?? $seeder->query()->max('id'))
                : $seeder->query()->max('id');

            while($continueAt !== null) {
                /** @var Collection $collection */
                $collection = $seeder->query()
                    ->latest('id')
                    ->whereBetween('id', [$continueAt - 1000, $continueAt])
                    ->when($this->option('max-id'), function ($query, $id) {
                        $query->where('id', '<=', $id);
                    })
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
