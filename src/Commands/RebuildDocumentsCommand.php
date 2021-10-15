<?php

namespace Blomstra\Search\Commands;

use Blomstra\Search\Observe\SavingJob;
use Blomstra\Search\Seeders\Seeder;
use Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Collection;

class RebuildDocumentsCommand extends Command
{
    protected $signature = 'blomstra:search:documents:rebuild {--flush : Flushes ALL the documents inside the index}';
    protected $description = 'Rebuilds the complete search server with its documents.';

    public function handle(Container $container)
    {
        /** @var array $seeders */
        $seeders = $container->tagged('blomstra.search.seeders');

        /** @var Queue $queue */
        $queue = $container->make(Queue::class);

        /** @var Client $client */
        $client = $container->make('blomstra.search.elastic');

        // Flush the index.
        if ($this->option('flush')) $client->indices()->delete([
            'index' => $container->make('blomstra.search.elastic_index')
        ]);

        /** @var Seeder $seeder */
        foreach ($seeders as $seeder) {
            $seeder->query()->chunk(50, function (Collection $collection) use ($queue, &$total) {

                $queue->push(new SavingJob($collection));

                $this->info("Pushed {$collection->count()} into the index.");

                $total += $collection->count();
            });

            $this->info("Pushed a total of $total into the index.");
        }
    }
}
