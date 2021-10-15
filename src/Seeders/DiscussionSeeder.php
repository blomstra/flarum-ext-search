<?php

namespace Blomstra\Search\Seeders;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Restored;
use Flarum\Discussion\Event\Started;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class DiscussionSeeder extends Seeder
{
    public function type(): string
    {
        return resolve(DiscussionSerializer::class)->type;
    }

    public function query(): Builder
    {
        return Discussion::query();
    }

    public static function savingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([Started::class, Restored::class], function ($event) use ($callable) {
            return $callable($event->discussion);
        });
    }

    public static function deletingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([Deleted::class, Hidden::class], function ($event) use ($callable) {
            return $callable($event->discussion);
        });
    }
}
