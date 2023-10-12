<?php

namespace Blomstra\Search\Search;

use Flarum\Search\AbstractDriver;

class ElasticSearchDriver extends AbstractDriver
{
    public static function name(): string
    {
        return 'blomstra-elasticsearch';
    }
}
