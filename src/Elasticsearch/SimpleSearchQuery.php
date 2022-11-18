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

use Spatie\ElasticsearchQueryBuilder\Queries\Query;

class SimpleSearchQuery implements Query
{
    protected float $boost = 1;
    protected ?string $analyzer = null;

    public static function create(array $field, string $value)
    {
        return new self($field, $value);
    }

    public function boost(float $boost = 1)
    {
        $this->boost = $boost;

        return $this;
    }

    public function analyzer(string $analyzer)
    {
        $this->analyzer = $analyzer;

        return $this;
    }

    public function __construct(
        protected array $fields,
        protected string $value
    ) {
    }

    public function toArray(): array
    {
        return [
            'simple_query_string' => [
                'query'            => $this->value,
                'fields'           => $this->fields,
                'analyzer'         => $this->analyzer,
                'default_operator' => 'AND',
                'boost'            => $this->boost,
            ],
        ];
    }
}
