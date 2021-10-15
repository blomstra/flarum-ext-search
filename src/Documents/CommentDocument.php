<?php

namespace Blomstra\Search\Documents;

use Flarum\Api\Serializer\PostSerializer;
use Flarum\Post\CommentPost;

class CommentDocument extends Document
{
    public function __construct(protected CommentPost $model)
    {}

    public function fulltext(): array
    {
        return [
            'content' => $this->model->content,
        ];
    }

    public function attributes(): array
    {
        $attributes = [
            'author' => $this->model->user_id,
            'createdAt' => $this->model->created_at->toAtomString(),
            'is_private' => $this->model->is_private,
            'discussion_id' => $this->model->discussion?->id
        ];

        if ($this->extensionEnabled('flarum-flags')) {
            $attributes['flags_count'] = $this->model->flags->count();
        }

        if ($this->extensionEnabled('flarum-approval')) {
            $attributes['approved'] = $this->model->is_approved;
        }

        return $attributes;
    }

    public function serializer(): string
    {
        return PostSerializer::class;
    }

    public function model(): string
    {
        return CommentPost::class;
    }
}
