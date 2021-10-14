<?php

namespace Blomstra\Search\Schemas;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

abstract class Schema
{
    protected function extensionEnabled(string $extension): bool
    {
        /** @var ExtensionManager $manager */
        $manager = resolve(ExtensionManager::class);

        return $manager->isEnabled($extension);
    }

    abstract public static function index(): string;

    abstract public static function model(): string;
    abstract public static function query(): Builder;

    abstract public static function serializer(): string;

    abstract public static function savingOn(Dispatcher $events, callable $callable);
    abstract public static function deletingOn(Dispatcher $events, callable $callable);

    protected function groupsForDiscussion(Discussion $discussion): array
    {
        $permissions = collect();

        $globalPermission = Permission::query()
            ->where('permission', 'viewForum')
            ->pluck('group_id');

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

        if (! $discussion->is_private && $permissions->isEmpty()) {
            $permissions = $globalPermission;
        }

        return $permissions->toArray();
    }
}
