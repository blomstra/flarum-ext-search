<?php

namespace Blomstra\Search;

use Flarum\Extend as Flarum;

return [
    (new Flarum\ServiceProvider)->register(Provider::class),

    (new Flarum\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js'),
    (new Flarum\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    (new Flarum\Locales(__DIR__ . '/resources/locale')),

    (new Flarum\Routes('api'))
        ->get('/blomstra/search/{type}', 'blomstra.search', Api\Controllers\SearchController::class)
        ->put('/blomstra/search/index', 'blomstra.search.index', Api\Controllers\IndexController::class),

    (new Flarum\Console)
        ->command(Commands\BuildCommand::class)
];
