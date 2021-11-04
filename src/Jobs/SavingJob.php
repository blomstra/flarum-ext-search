<?php

namespace Blomstra\Search\Jobs;

use Blomstra\Search\Exceptions\SeedingException;
use Elasticsearch\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class SavingJob extends Job
{
    public function handle(Client $client)
    {
        if ($this->models->isEmpty()) return;

        // Preparing body for storing.
        $body = $this->models->map(function (Model $model) {
            $document = $this->seeder->toDocument($model);

            return [
                ['index' => ['_index' => $this->index, '_id' => $document->id]],
                $document->toArray()
            ];
        })
        ->flatten(1);

        $response = $client->bulk([
            'index' => $this->index,
            'body' => $body->toArray(),
            'refresh' => true
        ]);

        if (Arr::get($response, 'errors') !== true) return true;

        $items = Arr::get($response, 'items');

        $error = Arr::get(Arr::first($items), 'index.error.reason');

        throw new SeedingException(
            "Failed to seed: $error",
            $items
        );
    }
}
