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
use Blomstra\Search\Content\AdminPayload;
use Flarum\Frontend\Document;
use Flarum\Testing\unit\TestCase;
use Illuminate\Contracts\View\Factory;
use Psr\Http\Message\ServerRequestInterface;

class AdminPayloadTest extends TestCase
{
    use BuildsConfig;

    private const CONFIG = [
        'endpoint' => 'http://opensearch:9200',
        'username' => 'cfg-user',
        'password' => 'cfg-secret',
        'index'    => 'cfg-index',
    ];

    // assertSame on the whole payload: the connection values must never be in it.

    /** @test */
    public function settings_from_the_database_leave_the_fields_on_offer()
    {
        $payload = $this->payloadFor(null);

        $this->assertSame(['blomstraSearchConnection' => ['fromConfig' => false, 'managed' => false]], $payload);
    }

    /** @test */
    public function a_connection_from_config_says_so()
    {
        $payload = $this->payloadFor(self::CONFIG);

        $this->assertSame(['blomstraSearchConnection' => ['fromConfig' => true, 'managed' => false]], $payload);
    }

    /** @test */
    public function a_managed_connection_says_the_host_manages_it()
    {
        $payload = $this->payloadFor(self::CONFIG + ['managed' => true]);

        $this->assertSame(['blomstraSearchConnection' => ['fromConfig' => true, 'managed' => true]], $payload);
    }

    private function payloadFor(?array $search): array
    {
        // Database settings are present in every case: config must win without exposing them.
        $connection = Connection::resolve($this->config($search), $this->settings([
            'blomstra-search.elastic-endpoint' => 'https://db-host:9200',
            'blomstra-search.elastic-password' => 'db-secret',
        ]));
        $document = new Document(
            $this->createMock(Factory::class),
            [],
            $this->createMock(ServerRequestInterface::class)
        );

        (new AdminPayload($connection))($document);

        return $document->payload;
    }
}
