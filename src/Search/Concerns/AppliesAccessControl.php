<?php

namespace Blomstra\Search\Search\Concerns;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use Flarum\User\User;

trait AppliesAccessControl
{
    protected static function groupsForUser(User $actor): array
    {
        $groups = $actor->groups->pluck('id');

        $groups->add(Group::GUEST_ID);

        if ($actor->is_email_confirmed) {
            $groups->add(Group::MEMBER_ID);
        }

        return $groups->toArray();
    }

    protected function groupsForDiscussion(Discussion $discussion): array
    {
        $permissions = collect();

        $globalPermission = Permission::query()
            ->where('permission', 'viewForum')
            ->pluck('group_id');

        if (resolve(ExtensionManager::class)->isEnabled('flarum-tags')) {
            /** @var \Illuminate\Database\Eloquent\Collection $tags */
            $tags = $discussion->tags;

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

        if (!$discussion->is_private && $permissions->isEmpty()) {
            $permissions = $globalPermission;
        }

        return $permissions->toArray();
    }
}
