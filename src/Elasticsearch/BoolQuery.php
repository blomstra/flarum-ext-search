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

namespace Blomstra\Search\Elasticsearch;

class BoolQuery extends \Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery
{
    protected ?int $minimumShouldMatch = null;

    public static function create(): static
    {
        return new self();
    }

    public function minimumShouldMatch(int $minimum): static
    {
        $this->minimumShouldMatch = $minimum;

        return $this;
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->minimumShouldMatch !== null) {
            $array['bool']['minimum_should_match'] = $this->minimumShouldMatch;
        }

        return $array;
    }
}
