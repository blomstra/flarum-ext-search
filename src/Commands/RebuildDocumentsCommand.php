<?php

namespace Blomstra\Search\Commands;

use Blomstra\Search\Observe\SavingJob;
use Blomstra\Search\Schemas\Schema;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use MeiliSearch\Client;

class RebuildDocumentsCommand extends Command
{
    protected $signature = 'blomstra:search:documents:rebuild {--flush : Flush the indices}';
    protected $description = 'Rebuilds the complete search server with its documents.';

    public function handle(Container $container)
    {
        /** @var array $schemas */
        $schemas = $container->tagged('blomstra.search.schemas');

        /** @var Queue $queue */
        $queue = $container->make(Queue::class);

        /** @var Client $meili */
        $meili = $container->make(Client::class);

        /** @var Schema $schema */
        foreach ($schemas as $schema) {
            /** @var Model $model */
            $model = $schema::model();

            // Flush the index.
            if ($this->option('flush')) $meili->index($schema::index())->delete();

            $model::query()->chunk(50, function (Collection $collection) use ($model, $queue) {
                $queue->push(new SavingJob($model, $collection));

                $this->info("Pushed {$collection->count()} into the index");
            });
        }
    }
}
