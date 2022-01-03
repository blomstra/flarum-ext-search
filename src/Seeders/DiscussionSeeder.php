<?php

namespace Blomstra\Search\Seeders;

use Blomstra\Search\Save\Document;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Restored;
use Flarum\Discussion\Event\Started;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DiscussionSeeder extends Seeder
{
    public function type(): string
    {
        return resolve(DiscussionSerializer::class)->getType(new Discussion);
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
        $events->listen([Started::class, Restored::class], function ($event) use ($callable) {
            return $callable($event->discussion);
        });
    }

    public static function deletingOn(Dispatcher $events, callable $callable)
    {
        $events->listen([Deleted::class, Hidden::class], function ($event) use ($callable) {
            return $callable($event->discussion);
        });
    }

    /**
     * @param Discussion $model
     * @return Document
     */
    public function toDocument(Model $model): Document
    {
        $document = new Document([
            'type' => $this->type(),
            'id' => $this->type() . ':' . $model->id,
            'content' => $model->title,
            'content_partial' => $model->title,
            'created_at' => $model->created_at?->toAtomString(),
            'updated_at' => $model->last_posted_at?->toAtomString(),
            'is_private' => $model->is_private,
            'user_id' => $model->user_id,
            'groups' => $this->groupsForDiscussion($model),
            'comment_count' => $model->comment_count,
        ]);

        if ($this->extensionEnabled('fof-byobu')) {
            $document['recipient_users'] = $model->recipientUsers->pluck('id')->toArray();
            $document['recipient_groups'] = $model->recipientGroups->pluck('id')->toArray();
        }

        if ($this->extensionEnabled('flarum-sticky')) {
            $document['is_sticky'] = (bool) $model->is_sticky;
        }

        return $document;
    }
}
