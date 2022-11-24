<?php

namespace Blomstra\Search\Searchers;

use Blomstra\Search\Seeders\DiscussionSeeder;

class DiscussionSearcher extends Searcher
{
    protected string|null $seeder = DiscussionSeeder::class;

    public function enabled(): bool
    {
        $enabled = $this->setting('blomstra-search.search-discussion-subjects', true);

        return boolval($enabled);
    }

    public function boost(): float
    {
        return 1.5;
    }
}
