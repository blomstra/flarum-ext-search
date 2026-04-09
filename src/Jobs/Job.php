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

namespace Blomstra\Search\Jobs;

use Blomstra\Search\Seeders\Seeder;
use Flarum\Queue\AbstractJob;
use Illuminate\Support\Collection;

abstract class Job extends AbstractJob
{
    protected string $index;

    public static ?string $onQueue = null;

    /**
     * @param string|null $targetIndex  Explicit index name for blue-green builds.
     *                                  Defaults to the configured alias when null.
     */
    public function __construct(protected Collection $models, protected Seeder $seeder, ?string $targetIndex = null)
    {
        $this->index = $targetIndex ?? resolve('blomstra.search.elastic_index');

        if (static::$onQueue) {
            $this->onQueue(static::$onQueue);
        }
    }
}
