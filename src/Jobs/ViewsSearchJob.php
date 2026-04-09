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

namespace Blomstra\Search\Jobs;

use Elasticsearch\Client;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Queue\AbstractJob;

class ViewsSearchJob extends AbstractJob
{
    protected string $index;
    protected string $documentType;

    public function __construct(protected int $discussionId)
    {
        $this->index        = resolve('blomstra.search.elastic_index');
        $this->documentType = resolve(DiscussionSerializer::class)->getType(new Discussion());

        if (Job::$onQueue) {
            $this->onQueue(Job::$onQueue);
        }
    }

    public function handle(Client $client): void
    {
        $discussion = Discussion::find($this->discussionId);

        if (!$discussion) {
            return;
        }

        $type = $this->documentType;

        try {
            $client->update([
                'index'             => $this->index,
                'id'                => "$type:{$this->discussionId}",
                'routing'           => (string) $this->discussionId,
                'retry_on_conflict' => 3,
                'body'              => [
                    'doc' => ['view_count' => (int) $discussion->view_count],
                ],
            ]);
        } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
            // Document not yet indexed; will be picked up on next --seed-missing or --recreate.
        }
    }
}
