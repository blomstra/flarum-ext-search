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

namespace Blomstra\Search\Jobs;

use Blomstra\Search\Exceptions\SeedingException;
use Blomstra\Search\LiveIndex;
use Blomstra\Search\Seeders\Seeder;
use Elasticsearch\Client;
use Flarum\Queue\AbstractJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

abstract class Job extends AbstractJob
{
    public static ?string $onQueue = null;

    /** Retry up to 5 times on transient ES failures (e.g. NoAliveNodesException). */
    public int $tries = 5;

    /** Wait 65 s between retries — outlasts the StaticNoPingConnectionPool dead-node window (default 60 s). */
    public int $backoff = 65;

    /**
     * @param string|null $index  Explicit index for blue-green builds, null for the live index.
     *                            Named as before, so jobs queued by older versions keep their target.
     */
    public function __construct(protected Collection $models, protected Seeder $seeder, protected ?string $index = null)
    {
        if (static::$onQueue) {
            $this->onQueue(static::$onQueue);
        }
    }

    /**
     * To the index resolved now, so a job queued before a promote follows it; a no-op before the
     * first build. Failed items throw for a retry: a write that raced a promote fails on the
     * deleted index, and the retry resolves the new one. Deleting a missing document is no error.
     */
    protected function bulk(Client $client, LiveIndex $live, array $body, string $action, array $params = []): void
    {
        if (!$index = $this->index ?? $live->name()) {
            return;
        }

        $response = $client->bulk(['index' => $index, 'body' => $body] + $params);

        if (Arr::get($response, 'errors') !== true) {
            return;
        }

        $items = Arr::get($response, 'items');

        $failed = array_filter($items, fn ($item) => isset($item[$action]['error']));
        $error  = Arr::get(Arr::first($failed), "$action.error.reason", 'unknown error');

        throw new SeedingException(
            "Failed to $action: $error (" . count($failed) . '/' . count($items) . ' items failed)',
            $items
        );
    }
}
