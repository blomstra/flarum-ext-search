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

use Blomstra\Search\Elasticsearch\HasChildQuery;
use Blomstra\Search\Elasticsearch\MatchPhraseQuery;
use Blomstra\Search\Elasticsearch\MatchQuery;
use Blomstra\Search\Elasticsearch\TermsQuery;
use Blomstra\Search\Searchers\CommentPostSearcher;
use Blomstra\Search\Searchers\DiscussionSearcher;
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
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Spatie\ElasticsearchQueryBuilder\Builder;
use Blomstra\Search\Elasticsearch\BoolQuery;
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
        'view_count'   => 'view_count',
    ];

    protected bool $matchSentences;
    protected bool $matchWords;
    protected ?Searcher $discussionSearcher;
    protected ?Searcher $postSearcher;

    public function __construct(protected Client $elastic, protected UrlGenerator $uri, Container $container, SettingsRepositoryInterface $settings)
    {
        $this->matchSentences = true;
        $this->matchWords     = true;

        $searchers = collect($container->tagged('blomstra.search.searchers'));

        $this->discussionSearcher = $searchers->first(fn ($s) => $s instanceof DiscussionSearcher);
        $this->postSearcher       = $searchers->first(fn ($s) => $s instanceof CommentPostSearcher);
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor   = RequestUtil::getActor($request);
        $filters = $this->extractFilter($request);
        $search  = $this->getSearch($filters);
        $limit   = $this->extractLimit($request);
        $offset  = $this->extractOffset($request);
        $include = array_merge($this->extractInclude($request), ['state']);

        $knownSortFields = array_merge(array_values($this->translateSort), ['rawId']);
        $logger          = resolve(LoggerInterface::class);

        $phpSortField = null;
        $phpSortDir   = 'desc';
        $needsScoring = true;
        $sorts        = [];

        foreach ($this->extractSort($request) as $field => $direction) {
            $translated = $this->translateSort[$field] ?? $field;

            if (!in_array($translated, $knownSortFields)) {
                $logger->warning("blomstra/search: unknown sort field \"{$field}\", ignoring.");
                continue;
            }

            $sorts[]      = new Sort($translated, $direction);
            $needsScoring = false;

            if ($phpSortField === null && $translated !== 'rawId') {
                $phpSortField = $translated;
                $phpSortDir   = $direction;
            }
        }

        // Default to latest when no explicit sort is requested. This lets has_child use
        // score_mode:none, which skips child scoring entirely and lets ES short-circuit
        // early on large corpora. Relevance is still available via sort=relevant if added.
        if (empty($sorts)) {
            $needsScoring = false;
            $phpSortField = 'updated_at';
            $phpSortDir   = 'desc';
            $sorts[]      = new Sort('updated_at', 'desc');
        }

        $query = BoolQuery::create()
            // Always restrict to discussion documents; posts are only searched via has_child.
            ->add(TermQuery::create('join_field', 'discussion'), 'filter');

        if (!empty($search)) {
            $query->add($this->buildTextQuery($search, $actor, $needsScoring));
        }

        $this->addFilters($query, $actor, $filters);

        $builder = (new Builder($this->elastic))
            ->index(resolve('blomstra.search.elastic_index'))
            ->addQuery($query);

        foreach ($sorts as $sort) {
            $builder->addSort($sort);
        }

        // track_total_hits: false lets ES stop counting once it has collected
        // enough results in sort order, avoiding a full-index count on every query.
        $payload = $builder->getPayload();
        $payload['track_total_hits'] = false;

        $response = $this->elastic->search([
            'index' => resolve('blomstra.search.elastic_index'),
            'size'  => $limit + 1,
            'from'  => $offset,
            'body'  => $payload,
        ]);

        Discussion::setStateUser($actor);

        if (in_array('mostRelevantPost.user', $include)) {
            $include[] = 'mostRelevantPost.user.groups';

            if (!in_array('mostRelevantPost', $include)) {
                $include[] = 'mostRelevantPost';
            }
        }

        // All hits are discussion documents. Extract the best-matching post ID from
        // inner_hits when a has_child clause matched (i.e. the match came from a post).
        $results = Collection::make(Arr::get($response, 'hits.hits'))
            ->map(function ($hit) {
                // _id is "discussions:123" — parse the numeric part directly.
                $discussionId = Str::after($hit['_id'], 'discussions:');

                // rawId on the inner hit gives us the integer post ID.
                $bestPostId = Arr::get($hit, 'inner_hits.best_post.hits.hits.0._source.rawId');

                return [
                    'discussion_id'        => $discussionId,
                    'most_relevant_post_id' => $bestPostId,
                    'weight'               => Arr::get($hit, 'sort.0', Arr::get($hit, '_score', 0)),
                ];
            });

        $document->addPaginationLinks(
            $this->uri->to('api')->route('blomstra.search', ['type' => 'discussions']),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->count() > $limit ? null : 0
        );

        $results = $results->take($limit);

        $discussions = Discussion::query()
            ->when(
                $actor->isGuest() || !$actor->hasPermission('discussion.hide'),
                fn ($q) => $q->whereNull('hidden_at')
            )
            ->whereIn('id', $results->pluck('discussion_id')->filter())
            ->get()
            ->each(function (Discussion $discussion) use ($results) {
                $result = $results->firstWhere('discussion_id', $discussion->id);

                $discussion->most_relevant_post_id = $result['most_relevant_post_id']
                    ?? $discussion->first_post_id;
                $discussion->weight = $result['weight'] ?? 0;
            })
            ->keyBy('id')
            ->when(
                $phpSortField,
                fn ($c) => $phpSortDir === 'desc' ? $c->sortByDesc($phpSortField) : $c->sortBy($phpSortField),
                fn ($c) => $c->sortByDesc('weight')
            )
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

    /**
     * Build the text-matching portion of the query.
     *
     * Discussion titles are matched directly (filtered to join_field=discussion).
     * Post bodies are matched via has_child. When $needsScoring is true (no explicit
     * sort, i.e. relevance ordering), score_mode=sum accumulates child scores onto the
     * parent so that discussions with many strongly-matching posts rank higher. When
     * false (any explicit field sort), score_mode=none skips scoring entirely — ES only
     * checks whether a matching child exists, which is significantly cheaper on large
     * corpora.
     * inner_hits returns the best-matching post for use as mostRelevantPost.
     * Hidden posts are only included in matching for users with post.hide permission.
     */
    protected function buildTextQuery(string $search, User $actor, bool $needsScoring = false): BoolQuery
    {
        $textQuery = BoolQuery::create();

        if ($this->discussionSearcher?->enabled()) {
            $textQuery->add($this->buildShouldClauses($search, $this->discussionSearcher->boost()), 'should');
        }

        if ($this->postSearcher?->enabled()) {
            $postQuery = $this->buildShouldClauses($search, $this->postSearcher->boost());

            // Guests and non-moderators may not see hidden posts; exclude them from child matching.
            if ($actor->isGuest() || !$actor->hasPermission('post.hide')) {
                $postQuery->add(TermQuery::create('is_hidden', 'false'), 'filter');
            }

            // Without minimum_should_match, ES default MSM is 0 when a filter clause is present,
            // causing has_child to score every non-hidden post instead of only matching ones.
            $postQuery->minimumShouldMatch(1);

            $textQuery->add(
                HasChildQuery::create('post', $postQuery, $needsScoring ? 'sum' : 'none')->withInnerHits(),
                'should'
            );
        }

        return $textQuery;
    }

    protected function buildShouldClauses(string $search, float $boost): BoolQuery
    {
        $should = BoolQuery::create();

        if ($this->matchSentences) {
            $should->add((new MatchPhraseQuery('content', $search))->boost(2 * $boost), 'should');
        }
        if ($this->matchWords) {
            $should->add((new MatchQuery('content', $search))->operator('and')->boost(1.8 * $boost), 'should');
        }

        return $should;
    }

    protected function extensionEnabled(string $extension): bool
    {
        return resolve(ExtensionManager::class)->isEnabled($extension);
    }

    protected function addFilters(BoolQuery $query, User $actor, array $filters = []): void
    {
        $groups      = $this->getGroups($actor);
        $onlyPrivate = Str::contains($filters['q'] ?? '', 'is:private');

        $subQuery = BoolQuery::create()
            ->add(TermQuery::create('is_private', 'false'))
            ->add(TermsQuery::create('groups', $groups->toArray()));

        if ($this->extensionEnabled('flarum-tags') && !empty($filters['tag'])) {
            $slugs  = is_array($filters['tag']) ? $filters['tag'] : explode(',', $filters['tag']);
            $tagIds = Tag::query()->whereIn('slug', $slugs)->pluck('id')->toArray();
            if (!empty($tagIds)) {
                $query->add(TermsQuery::create('tags', $tagIds), 'filter');
            }
        }

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

        $query->add($subQuery, 'filter');
    }

    protected function getGroups(User $actor): Collection
    {
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
            // Strip Flarum gambit operators (tag:foo, author:bar, is:private, etc.)
            // before passing to ES. These are structural filters handled separately;
            // leaving them in causes operator:and to require the gambit tokens to
            // appear literally in post content, producing zero results.
            $q = collect(explode(' ', $search))
                ->filter(fn (string $part) => !preg_match('/^\w+:/', $part))
                ->filter()
                ->join(' ');

            return empty($q) ? null : $q;
        }

        return null;
    }
}
