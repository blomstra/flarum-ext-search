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

use Elasticsearch\Client as Elastic;
use Elasticsearch\ClientBuilder;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Elastic::class, function (Container $container) {
            /** @var Config $config */
            $config = $this->container->make(Config::class);

            /** @var SettingsRepositoryInterface $settings */
            $settings = $this->container->make(SettingsRepositoryInterface::class);

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
    }

    public function boot(): void
    {
        //
    }
}
