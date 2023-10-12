<?php

namespace Blomstra\Search\Elasticsearch;

use Spatie\ElasticsearchQueryBuilder\Aggregations\Aggregation;

class BucketSortAggregation extends Aggregation
{
    protected array $fields = [];
    protected ?int $size = null;

    public static function create(string $name): self
    {
        return new static($name);
    }

    public function __construct(
        protected string $name
    ) {
    }

    public function field(string $field, string $order = 'asc'): self
    {
        $this->fields[] = [$field => ['order' => $order]];

        return $this;
    }

    public function size(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function payload(): array
    {
        $parameters = [
            'sort' => $this->fields,
        ];

        if ($this->size) {
            $parameters['size'] = $this->size;
        }

        return [
            'bucket_sort' => $parameters,
        ];
    }
}
