<?php

namespace Blomstra\Search\Jobs;

use Elasticsearch\Client;
use Illuminate\Database\Eloquent\Model;

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
    }
}