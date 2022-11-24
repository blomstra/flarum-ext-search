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

namespace Blomstra\Search\Api\Controllers;

use Blomstra\Search\Elasticsearch\MatchPhraseQuery;
use Blomstra\Search\Elasticsearch\MatchQuery;
use Blomstra\Search\Elasticsearch\TermsQuery;
use Blomstra\Search\Save\Document as ElasticDocument;
use Blomstra\Search\Searchers\Searcher;
use Elasticsearch\Client;
use Flarum\Api\Controller\ListDiscussionsController;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;
use Spatie\ElasticsearchQueryBuilder\Builder;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\Query;
use Spatie\ElasticsearchQueryBuilder\Queries\TermQuery;
use Spatie\ElasticsearchQueryBuilder\Sorts\Sort;
use Tobscure\JsonApi\Document;

class SearchController extends ListDiscussionsController
{
    public $serializer = DiscussionSerializer::class;

    protected array $translateSort = [
        'lastPostedAt' => 'updated_at',
        'createdAt'    => 'created_at',
        'commentCount' => 'comment_count',
    ];

    protected Collection $searchers;
    protected bool $matchSentences;
    protected bool $matchWords;

    public function __construct(protected Client $elastic, protected UrlGenerator $uri, Container $container, SettingsRepositoryInterface $settings)
    {
        $this->searchers = $this->gatherSearchers($container->tagged('blomstra.search.searchers'));

        $this->matchSentences = true;
        $this->matchWords = true;
    }

    protected function gatherSearchers(iterable $searchers)
    {
        return collect($searchers)
            ->map(fn ($searcher) => new $searcher)
            ->filter(fn (Searcher $searcher) => $searcher->enabled());
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        // Not used for now.
        $type = Arr::get($request->getQueryParams(), 'type');

        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);

        $search = $this->getSearch($filters);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);

        $include = array_merge($this->extractInclude($request), ['state']);

        $filterQuery = BoolQuery::create();


        if (! empty($search)) {
            if ($this->matchSentences) $filterQuery->add($this->sentenceMatch($search));
            if ($this->matchWords) $filterQuery->add($this->wordMatch($search, 'and'));
            if ($this->matchWords) $filterQuery->add($this->wordMatch($search, 'or'));
        }

        $builder = (new Builder($this->elastic))
            ->index(resolve('blomstra.search.elastic_index'))
            ->size($limit + 1)
            ->from($this->extractOffset($request))
            ->addQuery(
                $this->addFilters($filterQuery, $actor, $filters)
            );

        foreach ($this->extractSort($request) as $field => $direction) {
            $field = $this->translateSort[$field] ?? $field;
            $builder->addSort(new Sort($field, $direction));
        }

        $response = $builder->search();

        Discussion::setStateUser($actor);

        // Eager load groups for use in the policies (isAdmin check)
        if (in_array('mostRelevantPost.user', $include)) {
            $include[] = 'mostRelevantPost.user.groups';

            // If the first level of the relationship wasn't explicitly included,
            // add it so the code below can look for it
            if (!in_array('mostRelevantPost', $include)) {
                $include[] = 'mostRelevantPost';
            }
        }

        // we need to retrieve all discussion ids and when the results are posts,
        // their ids as most relevant post id
        $results = Collection::make(Arr::get($response, 'hits.hits'))
            ->map(function ($hit) {
                $type = $hit['_source']['type'];
                $id = Str::after($hit['_source']['id'], "$type:");

                if ($type === 'posts') {
                    return [
                        'most_relevant_post_id' => $id,
                        'weight'                => Arr::get($hit, 'sort.0'),
                    ];
                } else {
                    return [
                        'discussion_id' => $id,
                        'weight'        => Arr::get($hit, 'sort.0'),
                    ];
                }
            });

        $document->addPaginationLinks(
            $this->uri->to('api')->route('blomstra.search', [
                'type' => 'discussions',
            ]),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->count() > $limit ? null : 0
        );

        $results = $results->take($limit);

        $discussions = Discussion::query()
            ->select('discussions.*')
            ->join('posts', 'posts.discussion_id', 'discussions.id')
            // Extra safety to prevent leaking hidden discussion (titles) towards search results.
            ->when($actor->isGuest() || !$actor->hasPermission('discussion.hide'), fn ($query) => $query->whereNull('discussions.hidden_at'))
            ->where(function ($query) use ($results) {
                $query
                    ->whereIn('discussions.id', $results->pluck('discussion_id')->filter())
                    ->orWhereIn('posts.id', $results->pluck('most_relevant_post_id')->filter());
            })
            ->get()
            ->each(function (Discussion $discussion) use ($results) {
                if (in_array($discussion->id, $results->pluck('discussion_id')->toArray())) {
                    $discussion->most_relevant_post_id = $discussion->first_post_id;
                    $discussion->weight = $results->firstWhere('discussion_id', $discussion->id)['weight'] ?? 0;
                } else {
                    $post = $discussion->posts()->whereIn('id', $results->pluck('most_relevant_post_id'))->first();
                    $discussion->most_relevant_post_id = $post?->id ?? $discussion->first_post_id;
                    $discussion->weight = $results->firstWhere('most_relevant_post_id', $post?->id)['weight'] ?? 0;
                }
            })
            ->keyBy('id')
            ->sortByDesc('weight')
            ->unique();

        $this->loadRelations($discussions, $include);

        if ($relations = array_intersect($include, ['firstPost', 'lastPost', 'mostRelevantPost'])) {
            foreach ($discussions as $discussion) {
                foreach ($relations as $relation) {
                    if ($discussion->$relation) {
                        $discussion->$relation->discussion = $discussion;
                    }
                }
            }
        }

        return $discussions;
    }

    protected function getDocument(string $type): ?ElasticDocument
    {
        $documents = resolve(Container::class)->tagged('blomstra.search.documents');

        return collect($documents)->first(function (ElasticDocument $document) use ($type) {
            return $document->type() === $type;
        });
    }

    protected function extensionEnabled(string $extension): bool
    {
        /** @var ExtensionManager $manager */
        $manager = resolve(ExtensionManager::class);

        return $manager->isEnabled($extension);
    }

    protected function addFilters(BoolQuery $query, User $actor, array $filters = []): BoolQuery
    {
        $groups = $this->getGroups($actor);

        $onlyPrivate = Str::contains($filters['q'] ?? '', 'is:private');

        $subQuery = BoolQuery::create()
            ->add(TermQuery::create('is_private', 'false'))
            ->add(TermsQuery::create('groups', $groups->toArray()));

        if ($this->extensionEnabled('fof-byobu') && $actor->exists) {
            $byobuQuery = BoolQuery::create()
                ->add(TermQuery::create('is_private', 'true'))
                ->add(
                    BoolQuery::create()
                        ->add(TermsQuery::create('recipient_groups', $groups->toArray()), 'should')
                        ->add(TermsQuery::create('recipient_users', [$actor->id]), 'should'),
                );

            if ($onlyPrivate) {
                $subQuery = $byobuQuery;
            } else {
                $subQuery = BoolQuery::create()
                    ->add($subQuery, 'should')
                    ->add($byobuQuery, 'should');
            }
        }

        $query->add(
            $subQuery,
            'filter'
        );

        return $query;
    }

    protected function boolQuery(Query $parent, float $boost = 1): Query
    {
        $bool = new BoolQuery;

        /** @var Searcher $searcher */
        foreach ($this->searchers as $searcher) {
            $searcher = new $searcher;

            $bool->add(
                BoolQuery::create()
                    ->add(TermQuery::create('type', $searcher->type()), 'filter')
                    ->add(clone $parent->boost($boost * $searcher->boost())),
                'should'
            );
        }

        return $bool;
    }

    protected function sentenceMatch(string $q): Query
    {
        $query = (new MatchPhraseQuery('content', $q));

        return $this->boolQuery($query, 2);
    }

    protected function wordMatch(string $q, string $operator = 'or'): Query
    {
        $query = (new MatchQuery('content', $q))
            ->operator($operator);

        $boost = $operator === 'and' ? 1.8 : .8;

        return $this->boolQuery($query, $boost);
    }

    protected function getGroups(User $actor): Collection
    {
        /** @var Collection $groups */
        $groups = $actor->groups->pluck('id');

        $groups->add(Group::GUEST_ID);

        if ($actor->is_email_confirmed) {
            $groups->add(Group::MEMBER_ID);
        }

        return $groups;
    }

    protected function getSearch(array $filters): ?string
    {
        $search = Arr::get($filters, 'q');

        if ($search) {
            $q = collect(explode(' ', $search))
                ->filter(function (string $part) {
                    return $part !== 'is:private';
                })
                ->filter()
                ->join(' ');

            return empty($q) ? null : $q;
        }

        return null;
    }
}
