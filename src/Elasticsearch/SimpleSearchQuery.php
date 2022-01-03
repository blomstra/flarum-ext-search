<?php

namespace Blomstra\Search\Elasticsearch;

use Spatie\ElasticsearchQueryBuilder\Queries\Query;

class SimpleSearchQuery implements Query
{
    protected float $boost = 1;

    public static function create(array $field, string $value)
    {
        return new self($field, $value);
    }

    public function boost(float $boost = 1)
    {
        $this->boost = $boost;

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
                'query' => $this->value,
                'fields' => $this->fields,
                'boost' => $this->boost
            ]
        ];
    }
}
