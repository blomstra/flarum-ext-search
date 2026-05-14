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
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event as Core;
use Flarum\Post\Event as PostCore;
use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use FoF\Byobu\Events as Byobu;
use FoF\DiscussionViews\Events\DiscussionWasViewed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
        return Discussion::query()
            ->whereNull('hidden_at');
    }

    public function relationships(): array
    {
        $includes = [];

        if ($this->extensionEnabled('flarum-tags')) {
            $includes[] = 'tags';
        }

        if ($this->extensionEnabled('fof-byobu')) {
            $includes[] = 'recipientUsers';
            $includes[] = 'recipientGroups';
        }

        return $includes;
    }

    public static function savingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([
            // flarum/core events
            Core\Started::class, Core\Restored::class, Core\Renamed::class,
            // fof/byobu discussion recipients events.
            Byobu\DiscussionMadePublic::class, Byobu\RemovedSelf::class, Byobu\RecipientsChanged::class,
        ], function ($event) use ($callable) {
            return $callable($event->discussion);
        });

        // Re-index the parent discussion when a post is created, deleted, or restored so
        // that updated_at and recency_score stay current in the index.
        $events->listen([
            PostCore\Posted::class,
            PostCore\Deleted::class,
            PostCore\Hidden::class,
            PostCore\Restored::class,
        ], function ($event) use ($callable) {
            $discussion = $event->post->discussion;
            if ($discussion) {
                $callable($discussion->fresh());
            }
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
            'content'         => TextNormalizer::fold($model->title),
            'title'           => TextNormalizer::fold($model->title),
            'created_at'      => $model->created_at?->toAtomString(),
            'updated_at'      => $model->last_posted_at?->toAtomString(),
            'is_private'      => $model->is_private,
            'user_id'         => $model->user_id,
            'groups'          => $this->groupsForDiscussion($model),
            'comment_count'   => $model->comment_count,
            'recency_score'   => $this->computeRecencyScore($model),
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

    private function computeRecencyScore(Discussion $model): int
    {
        $reference = $model->last_posted_at ?? $model->created_at;
        $days = $reference ? (int) $reference->diffInDays(Carbon::now()) : 365;

        return max(0, 365 - $days);
    }

    /**
     * All viewForum permissions keyed by permission string, loaded once per seeder instance.
     * Avoids N×2 Permission queries inside the per-document map loop.
     */
    private ?Collection $cachedPermissions = null;
    private ?Collection $cachedGlobalPermission = null;

    private function allPermissions(): Collection
    {
        if ($this->cachedPermissions === null) {
            $this->cachedPermissions = Permission::query()
                ->where(function ($q) {
                    $q->where('permission', 'viewForum')
                      ->orWhere('permission', 'like', 'tag%.viewForum');
                })
                ->get();

            $this->cachedGlobalPermission = $this->cachedPermissions
                ->where('permission', 'viewForum')
                ->pluck('group_id');
        }

        return $this->cachedPermissions;
    }

    protected function groupsForDiscussion(Discussion $discussion): array
    {
        $allPerms = $this->allPermissions();
        $permissions = collect();

        if ($this->extensionEnabled('flarum-tags')) {
            /** @var Collection $tags */
            $tags = $discussion->tags;

            $permissions = $tags->map(function (Tag $tag) use ($allPerms) {
                $tagPerms = $allPerms->where('permission', "tag$tag->id.viewForum");

                if ($tag->is_restricted) {
                    $tagPerms = $tagPerms->add(['group_id' => Group::ADMINISTRATOR_ID]);
                }

                return $tagPerms->pluck('group_id');
            })->flatten();
        }

        if (!$discussion->is_private && $permissions->isEmpty()) {
            $permissions = $this->cachedGlobalPermission;
        }

        return $permissions->toArray();
    }
}
