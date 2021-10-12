<?php

namespace Blomstra\Search\Observe;

use Blomstra\Search\Mapping;
use Blomstra\Search\Searchables\Searchable;
use Flarum\Queue\AbstractJob;
use Illuminate\Database\Eloquent\Model;
use MeiliSearch\Client;

class Job extends AbstractJob
{
    public function __construct(protected Model $model)
    {}

    public function handle(Client $meili, Mapping $mapping)
    {
        $map = $mapping->get(get_class($this->model));

        /** @var Searchable $searchable */
        $searchable = new $map['searchable']($this->model);

        $body = array_merge([
            $searchable->fulltext()
        ], [
            $this->model->getKeyName() => $this->model->getKey()
        ]);

        $meili->index($map['index'])->addDocuments(
            [$body],
            $this->model->getKeyName()
        );
    }
}
