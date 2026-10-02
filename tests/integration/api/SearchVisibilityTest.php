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

namespace Blomstra\Search\Tests\integration\api;

use Carbon\Carbon;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use Illuminate\Support\Arr;

/**
 * The search index is a copy and can be stale. Whatever it returns, the API must only show what
 * Flarum's own visibility rules allow: these hits come back from a fake search server as if the
 * index still had them, and the database says otherwise.
 */
class SearchVisibilityTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('blomstra-search');

        $hidden = Carbon::now()->subDay();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'visible, matched post visible', 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 2, 'created_at' => $hidden],
                ['id' => 2, 'title' => 'hidden since it was indexed', 'user_id' => 1, 'first_post_id' => 3, 'comment_count' => 1, 'hidden_at' => $hidden, 'created_at' => $hidden],
                ['id' => 3, 'title' => 'private', 'user_id' => 1, 'first_post_id' => 4, 'comment_count' => 1, 'is_private' => 1, 'created_at' => $hidden],
                ['id' => 4, 'title' => 'visible, matched post hidden', 'user_id' => 1, 'first_post_id' => 5, 'comment_count' => 1, 'created_at' => $hidden],
                ['id' => 5, 'title' => 'visible, matched post private', 'user_id' => 1, 'first_post_id' => 7, 'comment_count' => 1, 'created_at' => $hidden],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>first</p></t>', 'created_at' => $hidden],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>match</p></t>', 'created_at' => $hidden],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>match</p></t>', 'created_at' => $hidden],
                ['id' => 4, 'discussion_id' => 3, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>match</p></t>', 'created_at' => $hidden],
                ['id' => 5, 'discussion_id' => 4, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>first</p></t>', 'created_at' => $hidden],
                ['id' => 6, 'discussion_id' => 4, 'number' => 2, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>match</p></t>', 'hidden_at' => $hidden, 'created_at' => $hidden],
                ['id' => 7, 'discussion_id' => 5, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>first</p></t>', 'created_at' => $hidden],
                ['id' => 8, 'discussion_id' => 5, 'number' => 2, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>match</p></t>', 'is_private' => 1, 'created_at' => $hidden],
            ],
        ]);
    }

    /** @test */
    public function hits_the_actor_may_not_see_are_dropped()
    {
        $data = $this->search();

        $this->assertEqualsCanonicalizing(['1', '4', '5'], Arr::pluck($data, 'id'));
    }

    /** @test */
    public function the_matched_post_is_shown_only_when_the_actor_may_see_it()
    {
        $mostRelevant = collect($this->search())->mapWithKeys(
            fn (array $discussion) => [$discussion['id'] => Arr::get($discussion, 'relationships.mostRelevantPost.data.id')]
        );

        $this->assertSame('2', $mostRelevant['1'], 'a visible match is kept');
        $this->assertSame('5', $mostRelevant['4'], 'a hidden match falls back to the first post');
        $this->assertSame('7', $mostRelevant['5'], 'a private match falls back to the first post');
    }

    /**
     * Search as a normal member, with the search server answering every discussion and its
     * best-matching post as a hit.
     */
    private function search(): array
    {
        $this->app()->getContainer()->instance(Client::class, $this->searchServerReturning([
            1 => 2,
            2 => 3,
            3 => 4,
            4 => 6,
            5 => 8,
        ]));

        $response = $this->send(
            $this->request('GET', '/api/blomstra/search/discussions', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['q' => 'match'], 'include' => 'mostRelevantPost'])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    /**
     * A client whose server answers every search with these discussion ids and, for each, the id
     * of its best-matching post.
     *
     * @param array<int, int> $bestPostByDiscussion
     */
    private function searchServerReturning(array $bestPostByDiscussion): Client
    {
        $hits = [];

        foreach ($bestPostByDiscussion as $discussionId => $postId) {
            $hits[] = [
                '_id'        => "discussions:$discussionId",
                '_score'     => 1.0,
                'inner_hits' => ['best_post' => ['hits' => ['hits' => [['_source' => ['rawId' => $postId]]]]]],
            ];
        }

        $body = json_encode(['hits' => ['hits' => $hits]]);

        return ClientBuilder::create()
            ->setHosts(['http://search.test:9200'])
            ->setHandler(function () use ($body) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $body);
                rewind($stream);

                return new CompletedFutureArray([
                    'status'         => 200,
                    'reason'         => 'OK',
                    'headers'        => ['Content-Type' => ['application/json']],
                    'body'           => $stream,
                    'effective_url'  => 'http://search.test:9200/_search',
                    'transfer_stats' => ['total_time' => 0],
                ]);
            })
            ->build();
    }
}
