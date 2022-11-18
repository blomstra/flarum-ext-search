<?php

/*
 * This file is part of ianm/translate.
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

    public function boost(float $boost = 1)
    {
        $this->boost = $boost;

        return $this;
    }

    public function toArray(): array
    {
        $query = parent::toArray()['match'];

        $query[$this->field]['boost'] = $this->boost;

        return [
            'match_phrase' => $query,
        ];
    }
}
