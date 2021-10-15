<?php

namespace Blomstra\Search\Observe;

use Illuminate\Database\Eloquent\Model;
use MeiliSearch\Client;

class DeletingJob extends Job
{
    public function handle(Client $meili)
    {
        if ($this->models->isEmpty()) return;

        $document = $this->getDocument();

        if (! $document) return;

        $keys = $this->models->map(function (Model $model) use ($document) {
            return (new $document)($model)->id();
        })->toArray();

        $meili
            ->index(resolve('blomstra.search.elastic_index'))
            ->deleteDocuments(
                $keys
            );
    }
}
