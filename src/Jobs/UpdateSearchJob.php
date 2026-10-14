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

use Blomstra\Search\LiveIndex;
use Elasticsearch\Client;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

class UpdateSearchJob extends Job
{
    public function handle(Client $client, LiveIndex $live)
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
                ['index' => ['_id' => $document->id, 'routing' => $routing]],
                $document->toArray(),
            ];
        })
        ->flatten(1);

        $this->bulk($client, $live, $body->toArray(), 'index');
    }
}
