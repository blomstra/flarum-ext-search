<?php

namespace Blomstra\Search\Api\Controllers;

use Blomstra\Search\Schemas\Schema;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use MeiliSearch\Client;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class SearchController extends AbstractListController
{
    public function __construct(protected Client $meili)
    {}

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $index = Arr::get($request->getQueryParams(), 'index');

        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);

        $schema = $this->getSchema($index);

        $result = $this->meili->index($index)->search($filters['q'], [
            'offset' => $this->extractOffset($request),
            'limit' => $this->extractLimit($request),
            'sort' => $this->extractSort($request),
            // Filters based on permissions etc..
            'filter' => $this->getFilters($actor)
        ]);

        $this->serializer = $schema::serializer();

        return Collection::make($result->getHits())
            ->map(function ($hit) use ($schema) {
                return $schema::model()::query()->find($hit['id']);
            });
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
