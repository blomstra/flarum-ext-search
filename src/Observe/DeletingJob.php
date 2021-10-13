<?php

namespace Blomstra\Search\Observe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MeiliSearch\Client;

class DeletingJob extends Job
{
    public function __construct(protected string $class, protected Collection $models)
    {}

    public function handle(Client $meili)
    {
        $schema = $this->getSchema();

        if (! $schema) return;

        $keys = $this->models->map(function (Model $model) {
            return $model->getKey();
        });

        $meili->index($schema::index())->deleteDocuments(
            $keys
        );
    }
}
