<?php

namespace Blomstra\Search\Discussion;

use Blomstra\Search\Save\Document;
use Blomstra\Search\Search\Concerns\AppliesAccessControl;
use Blomstra\Search\Search\ElasticIndex;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Search\IndexerInterface;

class DiscussionIndexer implements IndexerInterface
{
    use AppliesAccessControl;

    public function __construct(
        protected ElasticIndex $elastic,
        protected ExtensionManager $extensions
    ) {
    }

    public static function index(): string
    {
        return 'discussions';
    }

    public function save(array $models): void
    {
        $this->elastic->save(self::index(), $models, $this->toDocument(...));
    }

    public function delete(array $models): void
    {
        $this->elastic->delete(self::index(), $models);
    }

    function build(): void
    {
        $this->elastic->build(self::index(), $this->properties());
    }

    function flush(): void
    {
        $this->elastic->flush(self::index());
    }

    public function properties(): array
    {
        return [
            'rawId'            => ['type' => 'integer'],
            'discussion_id'    => ['type' => 'integer'],
            'title'            => ['type' => 'text', 'analyzer' => 'flarum_analyzer_partial', 'search_analyzer' => 'flarum_analyzer'],
            'created_at'       => ['type' => 'date'],
            'updated_at'       => ['type' => 'date'],
            'last_posted_at'   => ['type' => 'date'],
            'is_private'       => ['type' => 'boolean'],
            'is_sticky'        => ['type' => 'boolean'],
            'is_locked'        => ['type' => 'boolean'],
            'groups'           => ['type' => 'integer'],
            'tags'             => ['type' => 'integer'],
            'recipient_groups' => ['type' => 'integer'],
            'recipient_users'  => ['type' => 'integer'],
            'comment_count'    => ['type' => 'integer'],
        ];
    }

    public function toDocument(Discussion $model): Document
    {
        $document = new Document([
            'id'              => $model->id,
            'discussion_id'   => $model->id, // duplicated for result aggregation in searching with posts.
            'rawId'           => $model->id,
            'title'           => $model->title,
            'created_at'      => $model->created_at?->toAtomString(),
            'updated_at'      => $model->last_posted_at?->toAtomString(),
            'is_private'      => $model->is_private,
            'user_id'         => $model->user_id,
            'groups'          => $this->groupsForDiscussion($model),
            'comment_count'   => $model->comment_count,
        ]);

        if ($this->extensions->isEnabled('flarum-tags')) {
            $document['tags'] = $model->tags->pluck('id')->toArray();
        }

        if ($this->extensions->isEnabled('fof-byobu')) {
            $document['recipient_users'] = $model->recipientUsers->pluck('id')->toArray();
            $document['recipient_groups'] = $model->recipientGroups->pluck('id')->toArray();
        }

        if ($this->extensions->isEnabled('flarum-sticky')) {
            $document['is_sticky'] = $model->is_sticky;
        }

        if ($this->extensions->isEnabled('flarum-lock')) {
            $document['is_locked'] = $model->is_locked;
        }

        return $document;
    }
}
