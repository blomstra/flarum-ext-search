<?php

namespace Blomstra\Search\Documents;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Tags\Tag;
use Illuminate\Database\Eloquent\Collection;

abstract class Document
{
    abstract public function fulltext(): array;
    abstract public function attributes(): array;

    public function id(): string
    {
        return $this->type() . ':' . $this->model->getKey();
    }

    public function index(): string
    {
        return resolve('blomstra.search.elastic_index');
    }

    public function type(): string
    {
        return resolve($this->serializer())->getType($this->model);
    }

    abstract public function serializer(): string;

    abstract public function model(): string;

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

        if (! $discussion->is_private && $permissions->isEmpty()) {
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
