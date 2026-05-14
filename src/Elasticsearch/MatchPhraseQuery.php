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

use Spatie\ElasticsearchQueryBuilder\Queries\MatchQuery;

class MatchPhraseQuery extends MatchQuery
{
    protected float $boost = 1;
    protected ?int $slop = null;

    public function boost(float $boost = 1)
    {
        $this->boost = $boost;

        return $this;
    }

    public function slop(int $slop): static
    {
        $this->slop = $slop;

        return $this;
    }

    public function toArray(): array
    {
        $base   = parent::toArray()['match'][$this->field];
        $params = ['query' => $base['query'], 'boost' => $this->boost];

        if ($this->slop !== null) {
            $params['slop'] = $this->slop;
        }

        return ['match_phrase' => [$this->field => $params]];
    }
}
