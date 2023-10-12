<?php

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
        return DiscussionIndexer::index() . ',' . CommentPostIndexer::index();
    }

    function getQuery(User $actor): Builder
    {
        return Discussion::whereVisibleTo($actor)->select('discussions.*');
    }
}
