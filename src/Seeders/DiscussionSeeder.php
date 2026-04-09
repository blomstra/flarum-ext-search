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
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event as Core;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use FoF\Byobu\Events as Byobu;
use FoF\DiscussionViews\Events\DiscussionWasViewed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DiscussionSeeder extends Seeder
{
    public function type(): string
    {
        return resolve(DiscussionSerializer::class)->getType(new Discussion());
    }

    public function joinRelation(): string
    {
        return 'discussion';
    }

    public function routing(Model $model): string
    {
        return (string) $model->id;
    }

    public function query(): Builder
    {
        $includes = [];

        if ($this->extensionEnabled('flarum-tags')) {
            $includes[] = 'tags';
        }

        if ($this->extensionEnabled('fof-byobu')) {
            $includes[] = 'recipientUsers';
            $includes[] = 'recipientGroups';
        }

        return Discussion::query()
            ->whereNull('hidden_at')
            ->with($includes);
    }

    public static function savingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([
            // flarum/core events
            Core\Started::class, Core\Restored::class,
            // fof/byobu discussion recipients events.
            Byobu\DiscussionMadePublic::class, Byobu\RemovedSelf::class, Byobu\RecipientsChanged::class,
        ], function ($event) use ($callable) {
            return $callable($event->discussion);
        });
    }

    public static function viewingOn(Dispatcher $events, callable $callable): void
    {
        if (!resolve(ExtensionManager::class)->isEnabled('fof-discussion-views')) {
            return;
        }

        $events->listen(DiscussionWasViewed::class, function (DiscussionWasViewed $event) use ($callable) {
            $viewCount = $event->discussion->view_count;

            $shouldSync = match (true) {
                $viewCount < 15  => true,
                $viewCount < 100 => rand(1, 3) === 1,
                default          => rand(1, 19) === 1,
            };

            if ($shouldSync) {
                $callable($event->discussion->id);
            }
        });
    }

    public static function deletingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([
            // flarum/core events.
            Core\Deleted::class, Core\Hidden::class
        ], function ($event) use ($callable) {
            return $callable($event->discussion);
        });
    }

    /**
     * @param Discussion $model
     *
     * @return Document
     */
    public function toDocument(Model $model): Document
    {
        $document = new Document([
            'join_field'      => $this->joinRelation(),
            'id'              => $this->type().':'.$model->id,
            'rawId'           => $model->id,
            'content'         => $model->title,
            'created_at'      => $model->created_at?->toAtomString(),
            'updated_at'      => $model->last_posted_at?->toAtomString(),
            'is_private'      => $model->is_private,
            'user_id'         => $model->user_id,
            'groups'          => $this->groupsForDiscussion($model),
            'comment_count'   => $model->comment_count,
        ]);

        if ($this->extensionEnabled('flarum-tags')) {
            $document['tags'] = $model->tags->pluck('id')->toArray();
        }

        if ($this->extensionEnabled('fof-byobu')) {
            $document['recipient_users'] = $model->recipientUsers
                ->whereNull('removed_at')
                ->pluck('id')
                ->toArray();
            $document['recipient_groups'] = $model->recipientGroups
                ->whereNull('removed_at')
                ->pluck('id')
                ->toArray();
        }

        if ($this->extensionEnabled('flarum-sticky')) {
            $document['is_sticky'] = (bool) $model->is_sticky;
        }

        if ($this->extensionEnabled('fof-discussion-views')) {
            $document['view_count'] = (int) ($model->view_count ?? 0);
        }

        return $document;
    }

    protected function groupsForDiscussion(Discussion $discussion): array
    {
        $permissions = collect();

        $globalPermission = Permission::query()
            ->where('permission', 'viewForum')
            ->pluck('group_id');

        if ($this->extensionEnabled('flarum-tags')) {
            /** @var Collection $tags */
            $tags = $discussion->tags;

            $tagPermissions = Permission::query()
                ->whereIn(
                    'permission',
                    $tags->pluck('id')->map(fn (int $id) => "tag$id.viewForum")
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
