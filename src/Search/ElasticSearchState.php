<?php

namespace Blomstra\Search\Search;

use Closure;
use Flarum\Search\SearchState;
use Blomstra\Search\Elasticsearch\Builder;

class ElasticSearchState extends SearchState
{
    protected Builder $builder;
    protected ?Closure $retrieveDatabaseRecordsUsing = null;

    public function setBuilder(Builder $builder): void
    {
        $this->builder = $builder;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    public function retrieveDatabaseRecordsUsing(Closure $retrieveDatabaseRecordsUsing): void
    {
        $this->retrieveDatabaseRecordsUsing = $retrieveDatabaseRecordsUsing;
    }

    public function getRetrieveDatabaseRecordsUsing(): ?Closure
    {
        return $this->retrieveDatabaseRecordsUsing;
    }
}
