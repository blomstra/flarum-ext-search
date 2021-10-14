<?php

namespace Blomstra\Search\Schemas;

use Flarum\Api\Serializer\PostSerializer;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class CommentPostSchema extends Schema
{
    public function filters(CommentPost $post): array
    {
        $filters = [
            'author' => $post->user_id,
            'created_at' => $post->created_at->toAtomString(),
            'private' => $post->is_private,
            'groups' => $this->groupsForDiscussion($post->discussion)
        ];


        if ($this->extensionEnabled('fof-byobu')) {
            $filters['recipient-users'] = $post->discussion->recipientUsers->pluck('id')->toArray();
            $filters['recipient-groups'] = $post->discussion->recipientGroups->pluck('id')->toArray();
        }

        if ($this->extensionEnabled('fof-best-answer')) {
            $filters['best-answer-set'] = $post->discussion->best_answer_post_id !== null;
            $filters['best-answer-set-at'] = $post->discussion->best_answer_set_at?->toAtomString();
        }

        return $filters;
    }

    public function fulltext(CommentPost $post): array
    {
        return [
            'title' => $post->discussion->title,
            'content' => $post->content
        ];
    }

    public static function index(): string
    {
        return 'posts';
    }

    public static function model(): string
    {
        return CommentPost::class;
    }

    public static function query(): Builder
    {
        return CommentPost::query()
            ->where('type', CommentPost::$type);
    }

    public static function serializer(): string
    {
        return PostSerializer::class;
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
