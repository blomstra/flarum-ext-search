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

namespace Blomstra\Search\Tests\unit;

use Blomstra\Search\Provider;
use Elasticsearch\Client as Elastic;
use Elasticsearch\Connections\ConnectionInterface;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\unit\TestCase;
use Illuminate\Container\Container;

/**
 * The client and the index alias are built from the resolved connection, whichever source it
 * came from.
 */
class ProviderTest extends TestCase
{
    use BuildsConfig;

    /** @test */
    public function config_connection_reaches_the_client_and_the_alias()
    {
        $container = $this->boot(
            ['endpoint' => 'http://opensearch:9201', 'username' => 'cfg-user', 'password' => 'cfg-secret', 'index' => 'x1'],
            ['blomstra-search.elastic-endpoint' => 'https://db-host:9200', 'blomstra-search.elastic-index' => 'db-index']
        );

        $connection = $this->clientConnection($container);

        $this->assertSame('opensearch', $connection->getHost());
        $this->assertSame(9201, $connection->getPort());
        $this->assertSame('cfg-user:cfg-secret', $connection->getUserPass());
        $this->assertSame('x1', $container->make('blomstra.search.elastic_index'));
    }

    /** @test */
    public function settings_connection_reaches_the_client_and_the_alias()
    {
        $container = $this->boot(null, [
            'blomstra-search.elastic-endpoint' => 'https://db-host:9243',
            'blomstra-search.elastic-username' => 'db-user',
            'blomstra-search.elastic-password' => 'db-secret',
            'blomstra-search.elastic-index'    => 'db-index',
        ]);

        $connection = $this->clientConnection($container);

        $this->assertSame('db-host', $connection->getHost());
        $this->assertSame(9243, $connection->getPort());
        $this->assertSame('db-user:db-secret', $connection->getUserPass());
        $this->assertSame('db-index', $container->make('blomstra.search.elastic_index'));
    }

    /** @test */
    public function no_username_means_no_basic_auth()
    {
        $container = $this->boot(['endpoint' => 'http://opensearch:9200', 'password' => 'orphan'], []);

        $this->assertNull($this->clientConnection($container)->getUserPass());
    }

    private function boot(?array $search, array $settings): Container
    {
        $container = new Container();
        $container->instance(Config::class, $this->config($search));
        $container->instance(SettingsRepositoryInterface::class, $this->settings($settings));

        (new Provider($container))->register();

        return $container;
    }

    private function clientConnection(Container $container): ConnectionInterface
    {
        return $container->make(Elastic::class)->transport->getConnection();
    }
}
