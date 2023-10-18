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

namespace Blomstra\Search\Search;

use Blomstra\Search\Elasticsearch\Builder;
use Closure;
use Flarum\Search\SearchState;

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
