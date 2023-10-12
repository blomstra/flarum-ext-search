<?php

namespace Blomstra\Search\Search;

use Blomstra\Search\Exceptions\IndexingException;
use Closure;
use Elasticsearch\Client;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ElasticIndex
{
    public function __construct(
        protected Client $client,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function save(string $index, array $models, Closure $toDocument): void
    {
        $models = collect($models);

        // Preparing body for storing.
        $body = $models->map(function (Model $model) use ($index, $toDocument) {
            $document = $toDocument($model);

            return [
                [
                    'index' => [
                        '_index' => $index,
                        '_id' => $document->id
                    ]
                ],
                $document->toArray(),
            ];
        })
            ->flatten(1);

        $this->handleResponse(
            $this->client->bulk([
                'index'   => $index,
                'body'    => $body->toArray(),
                'refresh' => true,
            ])
        );
    }

    public function delete(string $index, array $models): void
    {
        $models = collect($models);

        $body = $models->map(function (Model $model) use ($index) {
            return [
                [
                    'delete' => [
                        '_index' => $index(),
                        '_id' => $model->id
                    ]
                ],
            ];
        })->flatten(1);

        $this->handleResponse(
            $this->client->bulk([
                'index'   => $index(),
                'body'    => $body->toArray(),
                'refresh' => true,
            ])
        );
    }

    public function build(string $index, array $properties): void
    {
        $this->client->indices()->create([
            'index' => $index,
            'body'  => [
                'settings' => [
                    'index.max_ngram_diff' => 10,
                    'analysis'             => [
                        'analyzer' => [
                            'flarum_analyzer' => [
                                'type' => $this->settings->get('blomstra-search.analyzer-language') ?: 'english',
                            ],
                            'flarum_analyzer_partial' => [
                                'type'      => 'custom',
                                'tokenizer' => 'standard',
                                'filter'    => [
                                    'lowercase',
                                    'partial_search_filter',
                                ],
                            ],
                        ],
                        'filter' => [
                            'partial_search_filter' => [
                                'type'        => 'ngram',
                                'min_gram'    => 1,
                                'max_gram'    => 10,
                                'token_chars' => ['letter', 'digit', 'symbol'],
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    'properties' => $properties,
                ],
            ],
        ]);
    }

    public function flush(string $index): void
    {
        $this->client->indices()->delete([
            'index'              => $index,
            'ignore_unavailable' => true,
        ]);
    }

    public function handleResponse(array $response): void
    {
        if (Arr::get($response, 'errors') !== true) {
            return;
        }

        $items = Arr::get($response, 'items');

        $error = Arr::get(Arr::first($items), 'index.error.reason');

        throw new IndexingException(
            "Failed to seed: $error",
            $items
        );
    }
}
