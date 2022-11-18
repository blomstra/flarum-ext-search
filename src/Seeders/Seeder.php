<?php

/*
 * This file is part of ianm/translate.
 *
 * Copyright (c) 2022 Blomstra Ltd.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 *
 */

namespace Blomstra\Search\Seeders;

use Blomstra\Search\Save\Document;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class Seeder
{
    abstract public function type(): string;

    abstract public function query(): Builder;

    abstract public static function savingOn(Dispatcher $events, callable $callable);

    abstract public static function deletingOn(Dispatcher $events, callable $callable);

    abstract public function toDocument(Model $model): Document;

    protected function groupsForDiscussion(Discussion $discussion): array
    {
        $permissions = collect();

        $globalPermission = Permission::query()
            ->where('permission', 'viewForum')
            ->pluck('group_id');

        if ($this->extensionEnabled('flarum-tags')) {
            /** @var Collection $tags */
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

        if (!$discussion->is_private && $permissions->isEmpty()) {
            $permissions = $globalPermission;
        }

        return $permissions->toArray();
    }

    protected function extensionEnabled(string $extension): bool
    {
        /** @var ExtensionManager $manager */
        $manager = resolve(ExtensionManager::class);

        return $manager->isEnabled($extension);
    }
}
