<?php

namespace Blomstra\Search\Api\Controllers;

use Blomstra\Search\Elasticsearch\TermsQuery;
use Blomstra\Search\Schemas\Schema;
use Elasticsearch\Client;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Api\Controller\ListDiscussionsController;
use Flarum\Discussion\Filter\DiscussionFilterer;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Spatie\ElasticsearchQueryBuilder\Aggregations\TermsAggregation;
use Spatie\ElasticsearchQueryBuilder\Aggregations\TopHitsAggregation;
use Spatie\ElasticsearchQueryBuilder\Builder;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\MultiMatchQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\TermQuery;
use Spatie\ElasticsearchQueryBuilder\Sorts\Sort;
use Tobscure\JsonApi\Document;

class SearchController extends ListDiscussionsController
{
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

        $schema = $this->getSchema($type);

        $this->serializer = $schema::serializer();

        $filterQuery = (BoolQuery::create())
            ->add(
                BoolQuery::create()
                    ->add(MultiMatchQuery::create($filters['q'], array_keys($schema->fulltext(new ($schema::model())))))
                    ->add(TermQuery::create('type', $type))
            );

        $builder = (new Builder($client))
            ->index(resolve('blomstra.search.elastic_index'))
            ->size($this->extractLimit($request))
            ->from($this->extractOffset($request))
            ->addQuery(
                $this->addFilters($filterQuery, $actor)
            )
            ->addAggregation(
                TermsAggregation::create('discussions', 'discussion_id')
                    ->aggregation(TopHitsAggregation::create('hits', 1))
            );

        foreach ($this->extractSort($request) as $field => $direction) {
            $builder->addSort(new Sort($field, $direction));
        }

        $result = $builder->search();

        return $schema::results(Arr::get($result, 'hits.hits'));
    }

    protected function getSchema(string $type): ?Schema
    {
        $mapping = resolve(Container::class)->tagged('blomstra.search.schemas');

        return collect($mapping)->first(function (Schema $schema) use ($type) {
            return $schema::type() === $type;
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
            ->add(TermQuery::create('private', 'false'))
            ->add(TermsQuery::create('groups', $groups->toArray()));

        if ($this->extensionEnabled('fof-byobu') && $actor->exists) {
            $byobuQuery = BoolQuery::create()
                ->add(TermQuery::create('private', 'true'), 'should')
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
