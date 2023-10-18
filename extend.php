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

namespace Blomstra\Search;

use Flarum\Discussion\Discussion as FlarumDiscussion;
use Flarum\Extend as Flarum;
use Flarum\Post\Post as FlarumPost;

return [
    (new Flarum\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),
    (new Flarum\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Flarum\Locales(__DIR__.'/resources/locale')),

    (new Flarum\ServiceProvider())
        ->register(Provider::class),

    (new Flarum\Routes('api'))
        ->put('/blomstra/search/index', 'blomstra.search.index', Api\Controllers\IndexController::class),

    (new Flarum\Console())
        ->command(Commands\BuildCommand::class),

    (new Flarum\Settings())
        ->default('blomstra-search.search-discussion-subjects', true)
        ->default('blomstra-search.search-post-bodies', true),

    (new Flarum\SearchDriver(Search\ElasticSearchDriver::class))
        ->addSearcher(FlarumDiscussion::class, Discussion\DiscussionSearcher::class)
        ->addFilter(Discussion\DiscussionSearcher::class, Discussion\PrivateFilterMutator::class)
        ->addMutator(Discussion\DiscussionSearcher::class, Discussion\PrivateFilterMutator::mutate(...))
        ->setFulltext(Discussion\DiscussionSearcher::class, Discussion\FulltextFilter::class),

    (new Flarum\SearchIndex())
        ->indexer(FlarumDiscussion::class, Discussion\DiscussionIndexer::class)
        ->indexer(FlarumPost::class, Post\CommentPostIndexer::class),
];
