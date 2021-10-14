<?php

namespace Blomstra\Search\Api\Controllers;

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

        $result = $client->search([
            'index' => $index,
            'from' => $this->extractOffset($request),
            'size' => $this->extractLimit($request),
            'sort' => $this->extractSort($request),
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $filters['q'],
                        'fields' => array_keys($schema->fulltext(new ($schema::model())))
                    ]
                ]
            ]
        ]);

        $this->serializer = $schema::serializer();

        $ids = Collection::make(Arr::get($result, 'hits.hits'))->pluck('_id')->toArray();

        return $schema::model()::query()->findMany($ids);
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

    protected function getFilters(User $actor): string
    {
        /** @var Collection $groups */
        $groups = $actor->groups->pluck('id');

        $groups->add(Group::GUEST_ID);

        if ($actor->is_email_confirmed) $groups->add(Group::MEMBER_ID);

        $filters = sprintf(
            '(%s)',
            join(' OR ', $groups->map(function(int $id) {
                return "groups = $id";
            })->toArray())
        );

        if ($this->extensionEnabled('fof-byobu')) {
            $filters .= sprintf(
                " OR (private = true AND (recipient-users = $actor->id OR %s))",
                join(' OR ', $groups->map(function(int $id) {
                    return "recipient-groups = $id";
                })->toArray())
            );
        }

        return $filters;
    }
}
