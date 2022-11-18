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

use Flarum\Extend as Flarum;

return [
    (new Flarum\ServiceProvider())->register(Provider::class),

    (new Flarum\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),
    (new Flarum\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Flarum\Locales(__DIR__.'/resources/locale')),

    (new Flarum\Routes('api'))
        ->get('/blomstra/search/{type}', 'blomstra.search', Api\Controllers\SearchController::class)
        ->put('/blomstra/search/index', 'blomstra.search.index', Api\Controllers\IndexController::class),

    (new Flarum\Console())
        ->command(Commands\BuildCommand::class),
];
