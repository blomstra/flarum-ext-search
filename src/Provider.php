<?php

namespace Blomstra\Search;

use Blomstra\Search\Observe\DeletingJob;
use Blomstra\Search\Observe\SavingJob;
use Blomstra\Search\Seeders;
use Elasticsearch\ClientBuilder;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Collection;
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

        $this->container->singleton('blomstra.search.elastic', function (Container $container) use ($settings) {
            $builder = ClientBuilder::create()
                ->setHosts([$settings->get('blomstra-search.elastic-endpoint')])
                ->setLogger($container->make(LoggerInterface::class));

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
                $queue->push(new SavingJob(Collection::make([$model]), $seeder));
            });

            $seeder::deletingOn($events, function ($model) use ($queue, $seeder) {
                $queue->push(new DeletingJob(Collection::make([$model]), $seeder));
            });
        }
    }
}
