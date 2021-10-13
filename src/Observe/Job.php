<?php

namespace Blomstra\Search\Observe;

use Blomstra\Search\Schemas\Schema;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;

abstract class Job extends AbstractJob
{
    protected function getSchema(): ?Schema
    {
        $mapping = resolve(Container::class)->tagged('blomstra.search.schemas');

        return collect($mapping)->first(function (Schema $schema) {
            return $schema::model() === $this->class;
        });
    }
}
