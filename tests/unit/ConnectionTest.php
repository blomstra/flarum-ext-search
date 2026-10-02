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

use Blomstra\Search\Connection;
use Flarum\Testing\unit\TestCase;

class ConnectionTest extends TestCase
{
    use BuildsConfig;

    private const DB_SETTINGS = [
        'blomstra-search.elastic-endpoint' => 'https://db-host:9200',
        'blomstra-search.elastic-username' => 'db-user',
        'blomstra-search.elastic-password' => 'db-secret',
        'blomstra-search.elastic-index'    => 'db-index',
    ];

    /** @test */
    public function without_search_config_the_settings_are_used()
    {
        $connection = Connection::resolve($this->config(), $this->settings(self::DB_SETTINGS));

        $this->assertSame('https://db-host:9200', $connection->endpoint());
        $this->assertSame('db-user', $connection->username());
        $this->assertSame('db-secret', $connection->password());
        $this->assertSame('db-index', $connection->index());
        $this->assertFalse($connection->fromConfig());
        $this->assertFalse($connection->managed());
    }

    /** @test */
    public function config_with_an_endpoint_replaces_every_setting()
    {
        // No username in config: the database's must not be paired with config's endpoint.
        $connection = Connection::resolve(
            $this->config(['endpoint' => 'http://opensearch:9200', 'index' => 'x1']),
            $this->settings(self::DB_SETTINGS)
        );

        $this->assertSame('http://opensearch:9200', $connection->endpoint());
        $this->assertNull($connection->username());
        $this->assertNull($connection->password());
        $this->assertSame('x1', $connection->index());
        $this->assertTrue($connection->fromConfig());
    }

    /** @test */
    public function config_without_an_endpoint_is_ignored()
    {
        $connection = Connection::resolve(
            $this->config(['username' => 'cfg-user', 'managed' => true]),
            $this->settings(self::DB_SETTINGS)
        );

        $this->assertSame('db-user', $connection->username());
        $this->assertFalse($connection->fromConfig());
        $this->assertFalse($connection->managed());
    }

    /** @test */
    public function managed_only_when_the_host_says_so()
    {
        $plain = Connection::resolve(
            $this->config(['endpoint' => 'http://opensearch:9200']),
            $this->settings([])
        );
        $managed = Connection::resolve(
            $this->config(['endpoint' => 'http://opensearch:9200', 'managed' => true]),
            $this->settings([])
        );

        $this->assertFalse($plain->managed());
        $this->assertTrue($managed->managed());
    }

    /** @test */
    public function index_defaults_to_flarum()
    {
        $fromSettings = Connection::resolve($this->config(), $this->settings([]));
        $fromConfig = Connection::resolve($this->config(['endpoint' => 'http://opensearch:9200']), $this->settings([]));

        $this->assertSame(Connection::DEFAULT_INDEX, $fromSettings->index());
        $this->assertSame(Connection::DEFAULT_INDEX, $fromConfig->index());
        $this->assertNull($fromSettings->endpoint());
    }

    /** @test */
    public function empty_settings_behave_as_unset()
    {
        // An admin who cleared a field saved an empty string, not null.
        $connection = Connection::resolve($this->config(), $this->settings([
            'blomstra-search.elastic-endpoint' => '',
            'blomstra-search.elastic-username' => '',
            'blomstra-search.elastic-password' => '',
            'blomstra-search.elastic-index'    => '',
        ]));

        $this->assertNull($connection->endpoint());
        $this->assertNull($connection->username());
        $this->assertNull($connection->password());
        $this->assertSame(Connection::DEFAULT_INDEX, $connection->index());
    }

    /** @test */
    public function managed_is_read_as_a_boolean()
    {
        foreach (['false' => false, '0' => false, '' => false, 'true' => true, '1' => true] as $value => $expected) {
            $connection = Connection::resolve(
                $this->config(['endpoint' => 'http://opensearch:9200', 'managed' => (string) $value]),
                $this->settings([])
            );

            $this->assertSame($expected, $connection->managed(), "managed => '$value'");
        }
    }
}
