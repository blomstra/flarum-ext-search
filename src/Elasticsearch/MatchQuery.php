<?php

namespace Blomstra\Search\Elasticsearch;

class MatchQuery extends \Spatie\ElasticsearchQueryBuilder\Queries\MatchQuery
{
    protected string $operator = 'or';
    protected float $boost = 1;
    protected ?string $analyzer = null;

    public function and()
    {
        $this->operator = 'and';

        return $this;
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

    public function toArray(): array
    {
        $query = parent::toArray();

        $query['match'][$this->field]['operator'] = $this->operator;
        $query['match'][$this->field]['boost'] = $this->boost;

        return $query;
    }
}
