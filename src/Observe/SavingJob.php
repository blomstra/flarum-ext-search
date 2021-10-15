<?php

namespace Blomstra\Search\Observe;

use Blomstra\Search\Documents\Document;
use Elasticsearch\Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

class SavingJob extends Job
{
    public function handle(Container $container)
    {
        if ($this->models->isEmpty()) return;

        /** @var Client $client */
        $client = $container->make('blomstra.search.elastic');

        $document = $this->getDocument();

        if (! $document) return;

        if($first = $this->models->first()) {
//            $properties = [];
//
//            foreach (array_keys($schema->fulltext($first)) as $key) {
//                $properties[$key] = ['type' => 'text'];
//            }

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
        $body = $this->models->map(function (Model $model) use ($document) {
            /** @var Document $markup */
            $markup = new $document($model);

            return [
                [
                    'index' => [
                        '_id' => $markup->id(),
                        '_index' => resolve('blomstra.search.elastic_index')
                    ]
                ],
                array_merge(
                    ['type' => $markup->type()],
                    $markup->fulltext(),
                    $markup->attributes()
                )
            ];
        })->flatten(1);

        $response = $client->bulk([
            'index' => resolve('blomstra.search.elastic_index'),
            'body' => $body->toArray(),
            'refresh' => true
        ]);
    }
}
