<?php

namespace Blomstra\Search\Api\Controllers;

use Blomstra\Search\Save\Document as ElasticDocument;
use Blomstra\Search\Elasticsearch\TermsQuery;
use Elasticsearch\Client;
use Flarum\Api\Controller\ListDiscussionsController;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;
use Spatie\ElasticsearchQueryBuilder\Aggregations\TermsAggregation;
use Spatie\ElasticsearchQueryBuilder\Aggregations\TopHitsAggregation;
use Spatie\ElasticsearchQueryBuilder\Builder;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\MatchQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\TermQuery;
use Spatie\ElasticsearchQueryBuilder\Sorts\Sort;
use Tobscure\JsonApi\Document;

class SearchController extends ListDiscussionsController
{
    public $serializer = DiscussionSerializer::class;

    protected array $translateSort = [
        'lastPostedAt' => 'updated_at',
        'createdAt' => 'created_at'
    ];

    public function __construct()
    {
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        /** @var Client $client */
        $client = resolve('blomstra.search.elastic');

        $type = Arr::get($request->getQueryParams(), 'type');

        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);

        $include = array_merge($this->extractInclude($request), ['state']);

        $filterQuery = (BoolQuery::create())
            ->add(
                BoolQuery::create()
                    ->add(MatchQuery::create('content', $filters['q']))
            );
        $builder = (new Builder($client))
            ->index(resolve('blomstra.search.elastic_index'))
            ->size($this->extractLimit($request))
            ->from($this->extractOffset($request))
            ->addQuery(
                $this->addFilters($filterQuery, $actor)
            )
//            ->addAggregation(
//                TermsAggregation::create('discussions', 'discussion_id')
//                    ->aggregation(TopHitsAggregation::create('hits', 1))
//            )
        ;

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
            if (! in_array('mostRelevantPost', $include)) {
                $include[] = 'mostRelevantPost';
            }
        }

        // we need to retrieve all discussion ids and when the results are posts,
        // their ids as most relevant post id
        $results = Collection::make(Arr::get($response, 'hits.hits'))
            ->map(function ($hit) {
                $id = Str::after($hit['_source']['id'], ':');
                $type = $hit['_source']['type'];

                if ($type === 'posts') {
                    return [
                        'most_relevant_post_id' => $id,
                    ];
                } else {
                    return [
                        'discussion_id' => $id
                    ];
                }
            });

        $discussions = Discussion::query()
            ->whereIn('id', $results->pluck('discussion_id'))
            ->orWhereHas('posts', function ($query) use ($results) {
                $query->whereIn('id', $results->pluck('most_relevant_post_id'));
            })
            ->get()
            ->each(function (Discussion $discussion) use ($results) {
                if (in_array($discussion->id, $results->pluck('discussion_id')->toArray())) {
                    $discussion->most_relevant_post_id = $discussion->first_post_id;
                } else {
                    $post = $discussion->posts()->whereIn('id', $results->pluck('most_relevant_post_id'))->first();
                    $discussion->most_relevant_post_id = $post?->id ?? $discussion->first_post_id;
                }
            })
            ->keyBy('id')
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

    protected function addFilters(BoolQuery $query, User $actor): BoolQuery
    {
        /** @var Collection $groups */
        $groups = $actor->groups->pluck('id');

        $groups->add(Group::GUEST_ID);

        if ($actor->is_email_confirmed) $groups->add(Group::MEMBER_ID);

        $subQuery = BoolQuery::create()
            ->add(TermQuery::create('is_private', 'false'))
            ->add(TermsQuery::create('groups', $groups->toArray()));

        if ($this->extensionEnabled('fof-byobu') && $actor->exists) {
            $byobuQuery = BoolQuery::create()
                ->add(TermQuery::create('is_private', 'true'), 'should')
                ->add(
                    BoolQuery::create()
                        ->add(TermsQuery::create('recipient-groups', $groups->toArray()))
                        ->add(TermQuery::create('recipient-users', $actor->id)),
                    'should'
                );

            $subQuery = BoolQuery::create()
                ->add($subQuery, 'should')
                ->add($byobuQuery, 'should');
        }

        $query->add(
            $subQuery,
            'filter'
        );

        return $query;
    }
}
