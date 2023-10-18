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

namespace Blomstra\Search\Search;

use Flarum\Search\AbstractDriver;

class ElasticSearchDriver extends AbstractDriver
{
    public static function name(): string
    {
        return 'blomstra-elasticsearch';
    }
}
