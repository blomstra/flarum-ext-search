<?php

namespace Blomstra\Search\Schemas;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Restored;
use Flarum\Discussion\Event\Started;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DiscussionSchema extends Schema
{
    public function filters(Discussion $discussion): array
    {
        $filters = [
            'type' => 'discussions',
            'author' => $discussion->user_id,
            'createdAt' => $discussion->created_at->toAtomString(),
            'lastPostedAt' => $discussion->last_posted_at->toAtomString(),
            'private' => $discussion->is_private,
            'first_post_id' => $discussion->first_post_id,
            'last_post_id' => $discussion->last_post_id,
            'commentCount' => $discussion?->comment_count,
            'groups' => $this->groupsForDiscussion($discussion),
        ];

        if ($this->extensionEnabled('fof-byobu')) {
            $filters['recipient-users'] = $discussion->recipientUsers->pluck('id')->toArray();
            $filters['recipient-groups'] = $discussion->recipientGroups->pluck('id')->toArray();
        }

        if ($this->extensionEnabled('flarum-sticky')) {
            $filters['is_sticky'] = $discussion->is_sticky;
        }

        return $filters;
    }

    public static function relations()
    {
        return [
            'discussion_id' => [
                'type' => 'discussion'
            ]
        ];
    }

    public function fulltext(Discussion $discussion): array
    {
        return [
            'title' => $discussion->title
        ];
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

    public static function query(): Builder
    {
        return Discussion::query();
    }

    public static function results(array $hits): Collection
    {
        $postIds = \Illuminate\Support\Collection::make($hits)->keyBy('_source.discussion_id')->pluck('_id');
        $discussionIds = Collection::make($hits)->pluck('_source.discussion_id');

        return Discussion::query()->findMany($discussionIds)->map(function (Discussion $discussion) use ($postIds) {
            $discussion->most_relevant_post_id = $postIds->get($discussion->id);

            return $discussion;
        })->load('mostRelevantPost');
    }

    public static function type(): string
    {
        return 'discussion';
    }
}
