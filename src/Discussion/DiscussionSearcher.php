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

use Blomstra\Search\Post\CommentPostIndexer;
use Blomstra\Search\Search\Searcher;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class DiscussionSearcher extends Searcher
{
    public function index(): string
    {
        return DiscussionIndexer::index().','.CommentPostIndexer::index();
    }

    public function getQuery(User $actor): Builder
    {
        return Discussion::whereVisibleTo($actor)->select('discussions.*');
    }
}
