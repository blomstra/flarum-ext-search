<?php

namespace Blomstra\Search\Observe;

use Blomstra\Search\Seeders\Seeder;
use Flarum\Queue\AbstractJob;
use Illuminate\Support\Collection;

abstract class Job extends AbstractJob
{
    protected string $index;

    public function __construct(protected Collection $models, protected Seeder $seeder)
    {
        $this->index = resolve('blomstra.search.elastic_index');
    }
}
