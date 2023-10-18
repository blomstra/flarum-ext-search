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

use Blomstra\Search\Elasticsearch\TermsQuery;
use Blomstra\Search\Search\Concerns\AppliesAccessControl;
use Blomstra\Search\Search\ElasticSearchState;
use Flarum\Extension\ExtensionManager;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Illuminate\Support\Arr;
use Spatie\ElasticsearchQueryBuilder\Queries\BoolQuery;
use Spatie\ElasticsearchQueryBuilder\Queries\TermQuery;

/**
 * @implements FilterInterface<ElasticSearchState>
 */
class PrivateFilterMutator implements FilterInterface
{
    use AppliesAccessControl;

    public function __construct(
        protected ExtensionManager $extensions
    ) {
    }

    public function getFilterKey(): string
    {
        return 'private';
    }

    public function filter(SearchState $state, array|string $value, bool $negate): void
    {
        $actor = $state->getActor();

        if (!$this->extensions->isEnabled('fof-byobu') || $actor->isGuest()) {
            return;
        }

        $builder = $state->getBuilder();
        $query = BoolQuery::create();

        $query->add(self::byobuAccessQuery($state), 'filter');

        $builder->addQuery($query);
    }

    public static function mutate(ElasticSearchState $state): void
    {
        $builder = $state->getBuilder();

        // If this filter isn't active, we apply both the private and public queries as should clauses.
        if (Arr::first($state->getActiveFilters(), fn ($filter) => $filter->getFilterKey() === 'private')) {
            return;
        }

        $query = BoolQuery::create();

        $query
            ->add(self::restrictQuery($state), 'should')
            ->add(self::byobuAccessQuery($state), 'should');

        $builder->addQuery($query);
    }

    private static function restrictQuery(SearchState $state): BoolQuery
    {
        return BoolQuery::create()
            ->add(TermQuery::create('is_private', 'false'))
            ->add(TermsQuery::create('groups', self::groupsForUser($state->getActor())));
    }

    private static function byobuAccessQuery(SearchState $state): BoolQuery
    {
        $actor = $state->getActor();

        return BoolQuery::create()
            ->add(TermQuery::create('is_private', 'true'))
            ->add(
                BoolQuery::create()
                    ->add(TermsQuery::create('recipient_groups', self::groupsForUser($actor)), 'should')
                    ->add(TermsQuery::create('recipient_users', [$actor->id]), 'should'),
            );
    }
}
