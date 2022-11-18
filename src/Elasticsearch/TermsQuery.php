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

use Spatie\ElasticsearchQueryBuilder\Queries\Query;

class TermsQuery implements Query
{
    protected string $field;

    protected array $values;

    public static function create(string $field, array $values): static
    {
        return new self($field, $values);
    }

    public function __construct(string $field, array $values)
    {
        $this->field = $field;
        $this->values = $values;
    }

    public function toArray(): array
    {
        return [
            'terms' => [
                $this->field => $this->values,
            ],
        ];
    }
}
