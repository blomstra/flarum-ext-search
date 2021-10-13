<?php

namespace Blomstra\Search\Schemas;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Started;
use Illuminate\Contracts\Events\Dispatcher;

class DiscussionSchema extends Schema
{
    public function filters(Discussion $discussion): array
    {
        $filters = [];

        if ($this->extensionEnabled('flarum-tags')) {
            $filters['tags'] = $discussion->tags->pluck('id')->toArray();
        }

        return $filters;
    }

    public function fulltext(Discussion $discussion): array
    {
        return [
            'title' => $discussion->title,
            'content' => $discussion->firstPost?->content
        ];
    }

    public static function index(): string
    {
        return 'discussions';
    }

    public static function model(): string
    {
        return Discussion::class;
    }

    public static function serializer(): string
    {
        return DiscussionSerializer::class;
    }

    public static function savingOn(Dispatcher $events, callable $callable)
    {
        $events->listen(Started::class, function (Started $event) use ($callable) {
            return $callable($event->discussion);
        });
    }

    public static function deletingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([Deleted::class, Hidden::class], function ($event) use ($callable) {
            if ($event->discussion->is_private) return;

            return $callable($event->discussion);
        });
    }
}
