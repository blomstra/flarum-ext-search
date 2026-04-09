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

use Spatie\ElasticsearchQueryBuilder\Queries\Query;

class HasChildQuery implements Query
{
    protected bool $innerHits = false;

    public function __construct(
        protected string $type,
        protected Query $query,
        protected string $scoreMode = 'sum'
    ) {}

    public static function create(string $type, Query $query, string $scoreMode = 'sum'): static
    {
        return new static($type, $query, $scoreMode);
    }

    /**
     * Include inner_hits so the best-matching post ID is available in the response.
     * ES returns the top-scoring child document per parent hit under inner_hits.best_post.
     */
    public function withInnerHits(): static
    {
        $this->innerHits = true;

        return $this;
    }

    public function toArray(): array
    {
        $hasChild = [
            'type'       => $this->type,
            'score_mode' => $this->scoreMode,
            'query'      => $this->query->toArray(),
        ];

        if ($this->innerHits) {
            $hasChild['inner_hits'] = ['name' => 'best_post', 'size' => 1];
        }

        return ['has_child' => $hasChild];
    }
}
