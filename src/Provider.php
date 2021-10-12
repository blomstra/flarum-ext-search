<?php

namespace Blomstra\Search;

use Blomstra\Search\Observe\Observer;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Post\CommentPost;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use MeiliSearch\Client;

class Provider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->singleton(Mapping::class, function () {
            return new Mapping([
                \Flarum\Discussion\Discussion::class => [
                    'index' => 'discussions',
                    'searchable' => \Blomstra\Search\Searchables\Discussion::class
                ],
                CommentPost::class => [
                    'index' => 'posts',
                    'searchable' => null,
                ]
            ]);
        });

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
        /** @var Mapping $searchables */
        $searchables = $this->container->get(Mapping::class);

        $searchables->each(function ($mapped, $model) {
            forward_static_call(
                [$model, 'observe'],
                Observer::class
            );
        });
    }
}
