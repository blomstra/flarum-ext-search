<?php

namespace Blomstra\Search\Observe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MeiliSearch\Client;

class SavingJob extends Job
{
    public function __construct(protected string $class, protected Collection $models)
    {}

    public function handle(Client $meili)
    {
        $schema = $this->getSchema();

        if (! $schema) return;

        if($first = $this->models->first()) {
            // Set up the index
            $meili->index($schema::index())->updateSettings([
                'filterableAttributes' => array_keys($schema->filters($first)),
                'searchableAttributes' => array_keys($schema->fulltext($first)),
            ]);
        }

        // Preparing body for storing.
        $body = $this->models->map(function (Model $model) use ($schema) {
            return array_merge(
                $schema->fulltext($model),
                $schema->filters($model), [
                $model->getKeyName() => $model->getKey()
            ]);
        });

        $meili->index($schema::index())->addDocuments(
            $body->toArray(),
            (new $this->class)->getKeyName()
        );
    }
}
