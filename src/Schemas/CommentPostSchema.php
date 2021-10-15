<?php

namespace Blomstra\Search\Schemas;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CommentPostSchema extends Schema
{
    public function filters(CommentPost $post): array
    {
        $filters = [
            'type' => CommentPost::$type,
            'author' => $post->user_id,
            'createdAt' => $post->created_at->toAtomString(),
            'private' => $post->is_private,
            'discussion_id' => $post->discussion?->id
        ];

        if ($this->extensionEnabled('flarum-flags')) {
            $filters['flags_count'] = $post->flags->count();
        }

        if ($this->extensionEnabled('flarum-approval')) {
            $filters['approved'] = $post->is_approved;
        }

        return $filters;
    }

    public function fulltext(CommentPost $post): array
    {
        return [
            'content' => $post->content,
        ];
    }

    public static function model(): string
    {
        return CommentPost::class;
    }

    public static function query(): Builder
    {
        return CommentPost::query()
            ->where('type', CommentPost::$type)
            ->with('discussion');
    }

    public static function results(array $hits): \Illuminate\Database\Eloquent\Collection
    {
        $postIds = Collection::make($hits)->keyBy('_source.discussion_id')->pluck('_id');
        $discussionIds = Collection::make($hits)->pluck('_source.discussion_id');

        return Discussion::query()->findMany($discussionIds)->map(function (Discussion $discussion) use ($postIds) {
            $discussion->most_relevant_post_id = $postIds->get($discussion->id);

            return $discussion;
        })->load('mostRelevantPost');
    }

    public static function serializer(): string
    {
        return DiscussionSerializer::class;
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

    public static function type(): string
    {
        return CommentPost::$type;
    }
}
