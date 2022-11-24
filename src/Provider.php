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

namespace Blomstra\Search;

use Blomstra\Search\Jobs\DeletingJob;
use Blomstra\Search\Jobs\Job;
use Blomstra\Search\Jobs\SavingJob;
use Blomstra\Search\Searchers;
use Blomstra\Search\Seeders;
use Elasticsearch\Client as Elastic;
use Elasticsearch\ClientBuilder;
use Flarum\Api\Client;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Http\Middleware\ExecuteRoute;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Collection;
use Laminas\Stratigility\MiddlewarePipe;
use Psr\Log\LoggerInterface;

class Provider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->tag([
            Seeders\DiscussionSeeder::class,
            Seeders\CommentSeeder::class,
        ], 'blomstra.search.seeders');

        /** @var SettingsRepositoryInterface $settings */
        $settings = $this->container->make(SettingsRepositoryInterface::class);

        /** @var Config $config */
        $config = $this->container->make(Config::class);

        $this->container->singleton(Elastic::class, function (Container $container) use ($settings, $config) {
            $builder = ClientBuilder::create()
                ->setHosts([$settings->get('blomstra-search.elastic-endpoint')]);

            if ($config->inDebugMode()) {
                $builder->setLogger($container->make(LoggerInterface::class));
            }

            if ($settings->get('blomstra-search.elastic-username')) {
                $builder->setBasicAuthentication(
                    $settings->get('blomstra-search.elastic-username'),
                    $settings->get('blomstra-search.elastic-password')
                );
            }

            return $builder->build();
        });

        $this->container->instance(
            'blomstra.search.elastic_index',
            $settings->get('blomstra-search.elastic-index', 'flarum')
        );

        $this->container->extend(
            Client::class,
            function () {
                $pipe = new MiddlewarePipe();

                $exclude = resolve('flarum.api_client.exclude_middleware');

                $middlewareStack = array_filter(resolve('flarum.api.middleware'), function ($middlewareClass) use ($exclude) {
                    return !in_array($middlewareClass, $exclude);
                });

                foreach ($middlewareStack as $middleware) {
                    $pipe->pipe(resolve($middleware));
                }

                $pipe->pipe(new ExecuteRoute());

                return new Api\Client($pipe);
            }
        );

        $this->container->tag([
            Searchers\DiscussionSearcher::class,
            Searchers\CommentPostSearcher::class,
        ], 'blomstra.search.searchers');
    }

    public function boot()
    {
        /** @var array|string[] $seeders */
        $seeders = $this->container->tagged('blomstra.search.seeders');

        /** @var Dispatcher $events */
        $events = resolve(Dispatcher::class);

        /** @var Queue $queue */
        $queue = resolve(Queue::class);

        /** @var string|Seeders\Seeder $seeder */
        foreach ($seeders as $seeder) {
            $seeder::savingOn($events, function ($model) use ($queue, $seeder) {
                $queue->pushOn(Job::$onQueue, new SavingJob(Collection::make([$model]), $seeder));
            });

            $seeder::deletingOn($events, function ($model) use ($queue, $seeder) {
                $queue->pushOn(Job::$onQueue, new DeletingJob(Collection::make([$model]), $seeder));
            });
        }
    }
}
