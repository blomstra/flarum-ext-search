<?php

namespace Blomstra\Search\Observe;

use Blomstra\Search\Documents\Document;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class Job extends AbstractJob
{
    public function __construct(protected Collection $models)
    {}

    protected function getDocument(): ?Document
    {
        $documents = resolve(Container::class)->tagged('blomstra.search.documents');

        /** @var Model $model */
        $model = $this->models->first();

        return collect($documents)->first(function (Document $document) use ($model) {
            return $document->model() === get_class($model);
        });
    }
}
