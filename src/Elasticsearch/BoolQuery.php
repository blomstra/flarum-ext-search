<?php

namespace Blomstra\Search\Elasticsearch;

class BoolQuery extends \Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery
{
    protected int $minimumShouldMatch = 1;

    public static function create(): static
    {
        return new self();
    }

    public function toArray(): array
    {
        $field = parent::toArray();

        $field['bool']['minimum_should_match'] = $this->minimumShouldMatch;

        return $field;
    }
}
