<?php

namespace Blomstra\Search\Api;

use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;

class Client extends \Flarum\Api\Client
{
    public function get(string $path): ResponseInterface
    {
        if ($path === '/discussions' && Arr::has($this->queryParams, 'filter.q')) return parent::get("/blomstra/search/discussions");

        return parent::get($path);
    }
}
