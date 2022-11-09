<?php

namespace Blomstra\Search\Searchers;

use Blomstra\Search\Seeders\CommentSeeder;

class CommentPostSearcher extends Searcher
{
    protected string|null $seeder = CommentSeeder::class;

    public function enabled(): bool
    {
        $enabled = $this->setting('blomstra-search.admin.search-post-bodies', true);

        return boolval($enabled);
    }
}
