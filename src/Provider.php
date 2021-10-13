<?php

namespace Blomstra\Search;

use Blomstra\Search\Observe\DeletingJob;
use Blomstra\Search\Observe\SavingJob;
use Blomstra\Search\Schemas\DiscussionSchema;
use Blomstra\Search\Schemas\Schema;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use MeiliSearch\Client;

class Provider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->tag([DiscussionSchema::class], 'blomstra.search.schemas');

        $this->container->singleton(Client::class, function (Container $container) {
            $config = $container->make('flarum.config') ?? [];

            $meili = Arr::get($config, 'meili');

            if (! $meili) {
                return null;
            }

            return new Client($meili);
        });
    }

    public function boot()
    {
        /** @var array $schemas */
        $schemas = $this->container->tagged('blomstra.search.schemas');

        /** @var Dispatcher $events */
        $events = resolve(Dispatcher::class);

        /** @var Queue $queue */
        $queue = resolve(Queue::class);

        /** @var Schema $schema */
        foreach ($schemas as $schema) {
            $schema::savingOn($events, function ($model) use ($schema, $queue) {
                $queue->push(new SavingJob($schema::model(), Collection::make([$model])));
            });

            $schema::deletingOn($events, function ($model) use ($schema, $queue) {
                $queue->push(new DeletingJob($schema::model(), Collection::make([$model])));
            });
        }
    }
}
