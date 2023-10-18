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

namespace Blomstra\Search\Search;

use Blomstra\Search\Elasticsearch\Builder;
use Elasticsearch\Client;
use Flarum\Search\Filter\FilterManager;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearcherInterface;
use Flarum\Search\SearchResults;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\ElasticsearchQueryBuilder\Sorts\Sort;

abstract class Searcher implements SearcherInterface
{
    public function __construct(
        protected FilterManager $filters,
        /** @var array<callable> */
        protected array $mutators,
        protected Client $elastic
    ) {
    }

    abstract public function index(): string;

    public function search(SearchCriteria $criteria): SearchResults
    {
        $builder = (new Builder($this->elastic))
            ->index($this->index())
            ->size($criteria->limit + 1)
            ->from($criteria->offset);

        $state = new ElasticSearchState($criteria->actor, $criteria->isFulltext());
        $state->setBuilder($builder);

        // Default logic for retrieving database records.
        // This is normally overriden by the fulltext filter.
        $state->retrieveDatabaseRecordsUsing(function (array $response, SearchCriteria $criteria): Collection {
            $results = (new Collection(Arr::get($response, 'hits.hits')))->map(function ($hit) {
                $type = $hit['_source']['type'];
                $id = Str::after($hit['_source']['id'], "$type:");

                return [
                    'id'     => $id,
                    'score'  => Arr::get($hit, '_score'),
                    'weight' => Arr::get($hit, 'sort.0'),
                ];
            })->sortByDesc('weight');

            $ids = $results
                ->take($criteria->limit)
                ->pluck('id')
                ->all();

            return $this->getQuery($criteria->actor)
                ->whereIn('id', $ids)
                ->orderByRaw('FIELD(id, '.implode(',', $ids).')')
                ->get();
        });

        $this->filters->apply($state, $criteria->filters);

        $this->applySort($state, $criteria);

        foreach ($this->mutators as $mutator) {
            $mutator($state, $criteria);
        }

//        echo json_encode($builder->getParams(), JSON_PRETTY_PRINT);
//        exit;

        $response = $builder->search();

//        header('Content-Type: application/json');
//        echo json_encode($response, JSON_PRETTY_PRINT);
//        exit;

        $areMoreResults = count($response['hits']['hits']) > $criteria->limit;

        $callback = $state->getRetrieveDatabaseRecordsUsing();

        if (!$callback) {
            throw new \RuntimeException('No callback set to retrieve database records');
        }

        $records = $callback($response, $criteria);

        return new SearchResults($records, $areMoreResults);
    }

    protected function applySort(ElasticSearchState $state, SearchCriteria $criteria): void
    {
        $sort = $criteria->sort;

        if ($criteria->sortIsDefault && !empty($state->getDefaultSort())) {
            $sort = $state->getDefaultSort();
        }

        if (is_callable($sort)) {
            $sort($state->getBuilder());
        } else {
            foreach (($criteria->sort ?? []) as $field => $direction) {
                $state->getBuilder()->addSort(new Sort(Str::snake($field), $direction));
            }
        }
    }
}
