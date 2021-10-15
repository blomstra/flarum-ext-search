<?php

namespace Blomstra\Search\Seeders;

use Flarum\Api\Serializer\PostSerializer;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class CommentSeeder extends Seeder
{
    public function type(): string
    {
        return resolve(PostSerializer::class)->type;
    }

    public function query(): Builder
    {
        return CommentPost::query()
            ->where('type', CommentPost::$type)
            ->with('discussion');
    }

    public static function savingOn(Dispatcher $events, callable $callable)
    {
        $events->listen(Posted::class, function (Posted $event) use ($callable) {
            $callable($event->post);
        });
    }

    public static function deletingOn(Dispatcher $events, callable $callable)
    {
        $events->listen(Deleted::class, function (Deleted $event) use ($callable) {
            $callable($event->post);
        });
    }
}
