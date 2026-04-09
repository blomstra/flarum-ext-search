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

    public function __construct(protected int $discussionId)
    {
        $this->index = resolve('blomstra.search.elastic_index');

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

        $type = resolve(DiscussionSerializer::class)->getType(new Discussion());

        $client->update([
            'index'             => $this->index,
            'id'                => "$type:{$this->discussionId}",
            'retry_on_conflict' => 3,
            'ignore'            => [404],
            'body'              => [
                'doc' => ['view_count' => (int) $discussion->view_count],
            ],
        ]);
    }
}
