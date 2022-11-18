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

namespace Blomstra\Search\Api;

use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;

class Client extends \Flarum\Api\Client
{
    public function get(string $path): ResponseInterface
    {
        if ($path === '/discussions' && Arr::has($this->queryParams, 'filter.q')) {
            return parent::get('/blomstra/search/discussions');
        }

        return parent::get($path);
    }
}
