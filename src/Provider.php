<?php

namespace Blomstra\Search;

use Blomstra\Search\Observe\DeletingJob;
use Blomstra\Search\Observe\SavingJob;
use Blomstra\Search\Schemas\CommentPostSchema;
use Blomstra\Search\Schemas\DiscussionSchema;
use Blomstra\Search\Schemas\Schema;
use Elasticsearch\ClientBuilder;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class Provider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->tag([CommentPostSchema::class], 'blomstra.search.schemas');

        $config = $this->container->make('flarum.config') ?? [];
        $elastic = Arr::get($config, 'elastic', []);

        $this->container->singleton('blomstra.search.elastic', function (Container $container) use ($elastic) {
            $builder = ClientBuilder::create()
                ->setHosts([$elastic['endpoint']])
                ->setLogger($container->make(LoggerInterface::class));

            if ($elastic['api-key'] ?? false) {
                $builder->setApiKey($elastic['api-id'], $elastic['api-key']);
            }
            if ($elastic['username'] ?? false) {
                $builder->setBasicAuthentication($elastic['username'], $elastic['password']);
            }

            return $builder->build();
        });

        $this->container->instance('blomstra.search.elastic_index', Arr::get($elastic, 'index', 'flarum'));
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
