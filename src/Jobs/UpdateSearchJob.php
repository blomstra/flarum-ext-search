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
use Elasticsearch\Client;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class UpdateSearchJob extends Job
{
    public function handle(Client $client)
    {
        if ($this->models->isEmpty()) {
            return;
        }

        $this->models->loadMissing($this->seeder->relationships());

        // Preparing body for storing.
        $body = $this->models->map(function (Model $model) {
            $document = $this->seeder->toDocument($model);
            $routing  = $this->seeder->routing($model);

            return [
                ['index' => ['_index' => $this->index, '_id' => $document->id, 'routing' => $routing]],
                $document->toArray(),
            ];
        })
        ->flatten(1);

        $response = $client->bulk([
            'index' => $this->index,
            'body'  => $body->toArray(),
        ]);

        if (Arr::get($response, 'errors') !== true) {
            return true;
        }

        $items = Arr::get($response, 'items');

        $failed = array_filter($items, fn ($item) => isset($item['index']['error']));
        $error  = Arr::get(Arr::first($failed), 'index.error.reason', 'unknown error');

        throw new SeedingException(
            "Failed to seed: $error (" . count($failed) . '/' . count($items) . ' items failed)',
            $items
        );
    }
}
