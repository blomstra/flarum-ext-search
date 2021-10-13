<?php

namespace Blomstra\Search\Schemas;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Started;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use Illuminate\Contracts\Events\Dispatcher;

class DiscussionSchema extends Schema
{
    public function filters(Discussion $discussion): array
    {
        $filters = [];

        $permissions = null;

        if ($this->extensionEnabled('flarum-tags')) {
            /** @var \Illuminate\Database\Eloquent\Collection $tags */
            $tags = $discussion->tags;

            $filters['tags'] = $tags->pluck('id')->toArray();
            $tagPermissions = Permission::query()
                ->whereIn(
                    'permission',
                    $tags->pluck('id')->map(function (int $id) {
                        return "tag$id.viewForum";
                    })
                )->get();

            $permissions = $tags->map(function (Tag $tag) use ($tagPermissions) {
                $permissions = $tagPermissions->where('permission', "tag$tag->id.viewForum");

                if ($tag->is_restricted) {
                    $permissions = $permissions->add(['group_id' => Group::ADMINISTRATOR_ID]);
                }

                return $permissions->pluck('group_id');
            })->flatten();
        }

        if (! $permissions) {
            $permissions = Permission::query()
                ->where('permission', 'viewForum')
                ->pluck('group_id')
                ->get();
        }

        $filters['groups'] = $permissions->toArray();

        $filters['private'] = $discussion->is_private;

        if ($this->extensionEnabled('fof-byobu')) {
            $filters['recipient-users'] = $discussion->recipientUsers->pluck('id')->toArray();
            $filters['recipient-groups'] = $discussion->recipientGroups->pluck('id')->toArray();
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
