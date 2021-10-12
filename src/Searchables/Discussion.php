<?php

namespace Blomstra\Search\Searchables;

use Flarum\Discussion\Discussion as Model;
use Flarum\Tags\Tag;

class Discussion extends Searchable
{
    public function __construct(
        protected Model $discussion
    ) {}

    public function filters(): ?array
    {
        if ($this->extensionEnabled('flarum-tags')) {
            return $this->discussion->tags->map(function (Tag $tag) {
                return "tag:$tag->id";
            })->toArray();
        }

        return [];
    }

    public function fulltext(): ?array
    {
        return [
            'title' => $this->discussion->title,
            'content' => $this->discussion->firstPost->content
        ];
    }
}
