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

namespace Blomstra\Search\Seeders;

use Blomstra\Search\Save\Document;
use Blomstra\Search\TextNormalizer;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Event as Core;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CommentSeeder extends Seeder
{
    public function type(): string
    {
        return resolve(PostSerializer::class)->getType(new CommentPost());
    }

    public function joinRelation(): string
    {
        return 'post';
    }

    public function routing(Model $model): string
    {
        return (string) $model->discussion_id;
    }

    public function query(): Builder
    {
        return CommentPost::query()
            ->where('type', CommentPost::$type);
    }

    public static function savingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([
            Core\Posted::class,
            Core\Revised::class,
            Core\Hidden::class,
            Core\Restored::class,
        ], function ($event) use ($callable) {
            $callable($event->post);
        });
    }

    public static function deletingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([
            Core\Deleted::class
        ], function ($event) use ($callable) {
            $callable($event->post);
        });
    }

    /** Cached discussion type string (e.g. "discussions") — resolved once per job. */
    private ?string $discussionType = null;

    private function discussionType(): string
    {
        return $this->discussionType ??= resolve(DiscussionSerializer::class)->getType(new Discussion());
    }

    /**
     * Post documents only need content for has_child matching and the join
     * field to establish the parent-child relationship. All discussion-level
     * fields (groups, tags, comment_count, etc.) live on the discussion
     * document and are irrelevant here.
     *
     * @param CommentPost $model
     */
    public function toDocument(Model $model): Document
    {
        return new Document([
            'join_field'    => ['name' => $this->joinRelation(), 'parent' => "{$this->discussionType()}:{$model->discussion_id}"],
            'discussion_id' => $model->discussion_id,
            'id'            => $this->type().':'.$model->id,
            'rawId'         => $model->id,
            'content'       => TextNormalizer::fold($model->content),
            'is_hidden'     => $model->hidden_at !== null,
        ]);
    }
}
