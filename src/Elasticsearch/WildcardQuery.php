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

class WildcardQuery extends \Spatie\ElasticsearchQueryBuilder\Queries\WildcardQuery
{
    protected float $boost = 1;
    protected bool $sensitivity = true;
    protected ?string $rewrite = null;

    public function boost(float $boost = 1)
    {
        $this->boost = $boost;

        return $this;
    }

    public function caseSensitivity(bool $sensitivity = true)
    {
        $this->sensitivity = $sensitivity;

        return $this;
    }

    public function rewrite(string $rewrite = null)
    {
        $this->rewrite = $rewrite;

        return $this;
    }

    public function toArray(): array
    {
        $query = parent::toArray();

        $query['wildcard'][$this->field]['boost'] = $this->boost;
        $query['wildcard'][$this->field]['case_insensitive'] = !$this->sensitivity;

        if ($this->rewrite) {
            $query['wildcard'][$this->field]['rewrite'] = $this->rewrite;
        }

        return $query;
    }
}
