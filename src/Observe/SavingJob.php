<?php

namespace Blomstra\Search\Observe;

use Elasticsearch\Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SavingJob extends Job
{
    public function __construct(protected string $class, protected Collection $models)
    {}

    public function handle(Container $container)
    {
        /** @var Client $client */
        $client = $container->make('blomstra.search.elastic');

        $schema = $this->getSchema();

        if (! $schema) return;

        if($first = $this->models->first()) {
            $properties = [];

            foreach (array_keys($schema->fulltext($first)) as $key) {
                $properties[$key] = ['type' => 'text'];
            }

            // Set up the index
//            if (! $client->indices()->exists([
//                'index' => $schema::index(),
//                'expand_wildcards' => 'none'
//            ])) {
//                $client->indices()->create([
//                    'index' => $schema::index(),
//                    'body'  => [
//                        'mappings' => [
//                            'properties' => $properties
//                        ],
//                        'settings' => [
//                            'index' => [
//                                'query' => [
//                                    'default_field' => array_keys($schema->fulltext($first))
//                                ]
//                            ]
//                        ]
//                    ]
//                ]);
//            }
        }

        // Preparing body for storing.
        $body = $this->models->map(function (Model $model) use ($schema) {
            return [
                ['index' => ['_id' => $model->getKey(), '_index' => $schema::index()]],
                array_merge(
                    $schema->fulltext($model),
                    $schema->filters($model))

            ];
        })->flatten(1);

        $response = $client->bulk([
            'index' => $schema::index(),
            'body' => $body->toArray(),
            'refresh' => true
        ]);
    }
}
