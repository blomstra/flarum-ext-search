<?php

namespace Blomstra\Search\Post;

use Blomstra\Search\Save\Document;
use Blomstra\Search\Search\Concerns\AppliesAccessControl;
use Blomstra\Search\Search\ElasticIndex;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Search\IndexerInterface;

class CommentPostIndexer implements IndexerInterface
{
    use AppliesAccessControl;

    public function __construct(
        protected ElasticIndex $elastic,
        protected ExtensionManager $extensions
    ) {
    }

    public static function index(): string
    {
        return 'posts';
    }

    function save(array $models): void
    {
        $this->elastic->save(
            self::index(),
            array_filter($models, fn (Post $model) => $model->type === CommentPost::$type),
            $this->toDocument(...)
        );
    }

    function delete(array $models): void
    {
        $this->elastic->delete(
            self::index(),
            array_filter($models, fn (Post $model) => $model->type === CommentPost::$type)
        );
    }

    public function build(): void
    {
        $this->elastic->build(self::index(), $this->properties());
    }

    public function flush(): void
    {
        $this->elastic->flush(self::index());
    }

    public function properties(): array
    {
        return [
            'rawId'            => ['type' => 'integer'],
            'content'          => ['type' => 'text', 'analyzer' => 'flarum_analyzer_partial', 'search_analyzer' => 'flarum_analyzer'],
            'discussion_id'    => ['type' => 'integer'],
            'created_at'       => ['type' => 'date'],
            'updated_at'       => ['type' => 'date'],
            'is_private'       => ['type' => 'boolean'],
            'groups'           => ['type' => 'integer'],
            'tags'             => ['type' => 'integer'],
            'recipient_groups' => ['type' => 'integer'],
            'recipient_users'  => ['type' => 'integer'],
            'comment_count'    => ['type' => 'integer'],
        ];
    }

    public function toDocument(Post $model): Document
    {
        $document = new Document([
            'id'              => $model->id,
            'type'            => $model->type,
            'rawId'           => $model->id,
            'discussion_id'   => $model->discussion_id,
            'content'         => $model->content,
            'created_at'      => $model->created_at?->toAtomString(),
            'updated_at'      => $model->edited_at?->toAtomString(),
            'is_private'      => $model->is_private,
            'user_id'         => $model->user_id,
            'groups'          => $this->groupsForDiscussion($model->discussion),
            'comment_count'   => $model->discussion->comment_count,
        ]);

        if ($this->extensions->isEnabled('flarum-tags')) {
            $document['tags'] = $model->discussion->tags->pluck('id')->toArray();
        }

        if ($this->extensions->isEnabled('fof-byobu')) {
            $document['recipient_users'] = $model->discussion->recipientUsers->pluck('id')->toArray();
            $document['recipient_groups'] = $model->discussion->recipientGroups->pluck('id')->toArray();
        }

        return $document;
    }
}
