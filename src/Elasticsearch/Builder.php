<?php

namespace Blomstra\Search\Elasticsearch;

class Builder extends \Spatie\ElasticsearchQueryBuilder\Builder
{
    protected array $collapse = [];

    public function collapse(string $field): static
    {
        $this->collapse = [
            'field' => $field,
        ];

        return $this;
    }

    public function getPayload(): array
    {
        $payload = parent::getPayload();

        if ($this->collapse) {
            $payload['collapse'] = $this->collapse;
        }

        return $payload;
    }

    public function search(): array
    {
        $params = $this->getParams();

        return $this->client->search($params);
    }

    public function getParams(): array
    {
        $payload = $this->getPayload();

        $params = [
            'body' => $payload,
        ];

        if ($this->searchIndex) {
            $params['index'] = $this->searchIndex;
        }

        if ($this->size !== null) {
            $params['size'] = $this->size;
        }

        if ($this->from !== null) {
            $params['from'] = $this->from;
        }

        return $params;
    }
}
