<?php

namespace Blomstra\Search\Elasticsearch;

use Spatie\ElasticsearchQueryBuilder\Aggregations\Aggregation;
use Spatie\ElasticsearchQueryBuilder\Aggregations\Concerns\WithMissing;

/**
 * Ported from spatie/elasticsearch-query-builder v2.1.0
 * @link https://github.com/spatie/elasticsearch-query-builder/blob/main/src/Aggregations/SumAggregation.php
 */
class SumAggregation extends Aggregation
{
    use WithMissing;

    protected string $field;

    public static function create(string $name, string $field): self
    {
        return new self($name, $field);
    }

    public function __construct(string $name, string $field)
    {
        $this->name = $name;
        $this->field = $field;
    }

    public function payload(): array
    {
        $parameters = [
            'field' => $this->field,
        ];

        if ($this->missing) {
            $parameters['missing'] = $this->missing;
        }

        return [
            'sum' => $parameters,
        ];
    }
}
