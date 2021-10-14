<?php

namespace Blomstra\Search\Api\Controllers;

use Blomstra\Search\Elasticsearch\TermsQuery;
use Blomstra\Search\Schemas\Schema;
use Elasticsearch\Client;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Spatie\ElasticsearchQueryBuilder\Builder;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\MultiMatchQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\TermQuery;
use Tobscure\JsonApi\Document;

class SearchController extends AbstractListController
{
    protected function data(ServerRequestInterface $request, Document $document)
    {
        /** @var Client $client */
        $client = resolve('blomstra.search.elastic');

        $index = Arr::get($request->getQueryParams(), 'index');

        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);

        $schema = $this->getSchema($index);

        $this->serializer = $schema::serializer();

        $filterQuery = (BoolQuery::create())
            ->add(
                MultiMatchQuery::create($filters['q'], array_keys($schema->fulltext(new ($schema::model())))),
                'must'
            );

        $result = (new Builder($client))
            ->index($index)
            ->size($this->extractLimit($request))
            ->from($this->extractOffset($request))
            ->addQuery(
                $this->addFilters($filterQuery, $actor)
            )
            ->search();

        $ids = Collection::make(Arr::get($result, 'hits.hits'))->pluck('_id')->toArray();

        return $schema::query()->findMany($ids);
    }

    protected function getSchema(string $index): ?Schema
    {
        $mapping = resolve(Container::class)->tagged('blomstra.search.schemas');

        return collect($mapping)->first(function (Schema $schema) use ($index) {
            return $schema::index() === $index;
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
