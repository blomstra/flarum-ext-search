<?php

namespace Blomstra\Search\Commands;

use Blomstra\Search\Observe\SavingJob;
use Blomstra\Search\Schemas\Schema;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class RebuildDocumentsCommand extends Command
{
    protected $signature = 'blomstra:search:documents:rebuild';
    protected $description = 'Rebuilds the complete search server with its documents.';

    public function handle(Container $container)
    {
        /** @var array $schemas */
        $schemas = $container->tagged('blomstra.search.schemas');

        /** @var Queue $queue */
        $queue = $container->make(Queue::class);

        /** @var Schema $schema */
        foreach ($schemas as $schema) {
            /** @var Model $model */
            $model = $schema::model();

            $model::query()->chunk(50, function (Collection $collection) use ($model, $queue) {
                $queue->push(new SavingJob($model, $collection));

                $this->info("Pushed {$collection->count()} into the index");
            });
        }
    }
}
