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

namespace Blomstra\Search\Discussion;

use Blomstra\Search\Elasticsearch\Builder;
use Blomstra\Search\Elasticsearch\MatchPhraseQuery;
use Blomstra\Search\Elasticsearch\MatchQuery;
use Blomstra\Search\Elasticsearch\TermsQuery;
use Blomstra\Search\Post\CommentPostIndexer;
use Blomstra\Search\Search\ElasticSearchState;
use Elasticsearch\Client;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchManager;
use Flarum\Search\SearchState;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Spatie\ElasticsearchQueryBuilder\Aggregations\FilterAggregation;
use Spatie\ElasticsearchQueryBuilder\Aggregations\TermsAggregation;
use Spatie\ElasticsearchQueryBuilder\Aggregations\TopHitsAggregation;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\Query;
use Spatie\ElasticsearchQueryBuilder\Sorts\Sort;

/**
 * Unlike the default flarum database search,
 * this search will not get score based on the sum of post scores of a discussion.
 *
 * @extends AbstractFulltextFilter<ElasticSearchState>
 */
class FulltextFilter extends AbstractFulltextFilter
{
    public function __construct(
        protected SearchManager $search,
        protected Client $elastic
    ) {
    }

    public function search(SearchState $state, string $value): void
    {
        $builder = $state->getBuilder();

        $query = BoolQuery::create()
            ->add(
                BoolQuery::create()
                    ->add($this->exactMatch('title', $value, 1.2), 'should')
                    ->add($this->wordMatch('title', $value, 'and', 1.2), 'should')
                    ->add($this->wordMatch('title', $value, 'or', 1.2), 'should'),
                'should'
            )
            ->add(
                BoolQuery::create()
                    ->add($this->exactMatch('content', $value), 'should', 1)
                    ->add($this->wordMatch('content', $value, 'and', 1), 'should')
                    ->add($this->wordMatch('content', $value, 'or', 1), 'should'),
                'should'
            );

        $builder->addQuery($query);

        $builder->collapse('discussion_id');

        $aggs = FilterAggregation::create('posts', TermsQuery::create('_index', [CommentPostIndexer::index()]))
            ->aggregation(
                TermsAggregation::create('per_discussion', 'discussion_id')
                    ->aggregation(
                        TopHitsAggregation::create('most_relevant_post_id', 1, Sort::create('_score'))
                    )
            );

        $builder->addAggregation($aggs);

        $state->setDefaultSort(function (Builder $builder) {
            $builder->addSort(
                Sort::create('_score', 'desc')
            );
        });

        $state->retrieveDatabaseRecordsUsing(function (array $response, SearchCriteria $criteria): Collection {
            $buckets = Collection::make(Arr::get($response, 'aggregations.posts.per_discussion.buckets'))
                ->map(fn (array $hit) => [
                    'discussion_id'            => $hit['key'],
                    'most_relevant_post_id'    => Arr::get($hit, 'most_relevant_post_id.most_relevant_post_id.hits.hits.0._id'),
                    'most_relevant_post_score' => Arr::get($hit, 'most_relevant_post_id.most_relevant_post_id.hits.hits.0._score'),
                ])->keyBy('discussion_id');

            $results = Collection::make(Arr::get($response, 'hits.hits'))
                ->map(fn (array $hit) => [
                    'discussion_id'            => $hit['_source']['discussion_id'],
                    'most_relevant_post_id'    => Arr::get($buckets->get($hit['_source']['discussion_id']), 'most_relevant_post_id'),
                    'most_relevant_post_score' => Arr::get($buckets->get($hit['_source']['discussion_id']), 'most_relevant_post_score'),
                    'title_score'              => $hit['_score'],
                ])->keyBy('discussion_id');

            // We have $hits and $buckets, both are sorted by score.
            // $discussionResult represents discussion scores based on the title.
            // $buckets represents discussion scores based on the sum of all posts,
            // and also the most relevant post id and its score.
            // We need to merge these two results into one collection, and sort them by score.
            // We also need to make sure that the most relevant post id is set on the discussion.
            $results = $results
                // Sort by title_score, most_relevant_post_score then posts_score.
                ->sortByDesc(function ($result) {
                    return $result['title_score'] ?? 0
                        + $result['most_relevant_post_score'] ?? 0
                        + $result['posts_score'] ?? 0;
                })
                ->take($criteria->limit);

            $actor = $criteria->actor;
            $connection = Post::query()->getConnection();

            if ($results->isEmpty()) {
                return Discussion::whereVisibleTo($actor)
                    ->select('discussions.*')
                    ->get();
            }

            return Discussion::whereVisibleTo($actor)
                ->select('discussions.*')
                ->selectRaw(
                    $connection->raw('COALESCE(('.$connection->getQueryGrammar()->compileSelect(
                        $postsQuery = Post::query()
                            ->select('posts.id')
                            ->whereIn('posts.id', $results->pluck('most_relevant_post_id')->filter())
                            ->whereColumn('discussions.id', '=', 'posts.discussion_id')
                            ->limit(1)
                            ->toBase()
                    ).'), first_post_id) as most_relevant_post_id')->getValue($connection->getQueryGrammar())
                )
                ->mergeBindings($postsQuery)
                ->whereIn('discussions.id', $results->pluck('discussion_id'))
                ->orderByRaw('FIELD(`discussions`.`id`, '.implode(',', $results->pluck('discussion_id')->all()).')')
                ->get();
        });
    }

    protected function exactMatch(string $field, string $q, int $fieldBoost = 1): Query
    {
        $query = (new MatchPhraseQuery($field, $q));

        return $query->boost(2 * $fieldBoost);
    }

    protected function wordMatch(string $field, string $q, string $operator, int $fieldBoost = 1): Query
    {
        $query = (new MatchQuery($field, $q))
            ->operator($operator);

        $boost = $operator === 'and' ? 1.8 : .8;

        return $query->boost($boost * $fieldBoost);
    }
}
