<?php

namespace Blomstra\Search\Documents;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;

class DiscussionDocument extends Document
{
    public function __construct(protected Discussion $model)
    {}

    public function fulltext(): array
    {
        return [
            'content' => $this->model->title
        ];
    }

    public function attributes(): array
    {
        $attributes = [
            'author' => $this->model->user_id,
            'createdAt' => $this->model->created_at->toAtomString(),
            'lastPostedAt' => $this->model->last_posted_at?->toAtomString(),
            'is_private' => $this->model->is_private,
            'first_post_id' => $this->model->first_post_id,
            'last_post_id' => $this->model->last_post_id,
            'commentCount' => $this->model?->comment_count,
            'groups' => $this->groupsForDiscussion($this->model),
        ];

        if ($this->extensionEnabled('fof-byobu')) {
            $attributes['recipient-users'] = $this->model->recipientUsers->pluck('id')->toArray();
            $attributes['recipient-groups'] = $this->model->recipientGroups->pluck('id')->toArray();
        }

        if ($this->extensionEnabled('flarum-sticky')) {
            $attributes['is_sticky'] = $this->model->is_sticky;
        }

        return $attributes;
    }

    public function serializer(): string
    {
        return DiscussionSerializer::class;
    }

    public function model(): string
    {
        return Discussion::class;
    }
}
