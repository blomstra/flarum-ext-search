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

namespace Blomstra\Search\Tests\integration\console;

use Blomstra\Search\Elasticsearch\AliasLookup;
use Carbon\Carbon;
use Elasticsearch\Client;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Illuminate\Support\Arr;

/**
 * The index lifecycle and search against a real search server, through the connection from
 * config.php and with the credentials a host would hand a forum: a user whose role covers only
 * `<index>*`. Skipped unless SEARCH_TEST_ENDPOINT, SEARCH_TEST_USERNAME, SEARCH_TEST_PASSWORD and
 * SEARCH_TEST_INDEX are set. Every index under that prefix is deleted before each test.
 *
 * Flarum's default queue is synchronous, so a build's jobs (seeding, promotion) have all run by
 * the time the command returns.
 */
class OpenSearchRoundTripTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    private string $alias;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('SEARCH_TEST_ENDPOINT')) {
            $this->markTestSkipped('No search server: set SEARCH_TEST_ENDPOINT, SEARCH_TEST_USERNAME, SEARCH_TEST_PASSWORD and SEARCH_TEST_INDEX.');
        }

        $this->alias = (string) getenv('SEARCH_TEST_INDEX');

        $this->extension('blomstra-search');
        $this->config('search', [
            'endpoint' => getenv('SEARCH_TEST_ENDPOINT'),
            'username' => getenv('SEARCH_TEST_USERNAME'),
            'password' => getenv('SEARCH_TEST_PASSWORD'),
            'index'    => $this->alias,
        ]);

        $at = Carbon::now()->subDay();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Unsere Katze schläft', 'user_id' => 1, 'first_post_id' => 1, 'last_post_id' => 2, 'comment_count' => 2, 'created_at' => $at, 'last_posted_at' => $at],
                ['id' => 2, 'title' => 'Gartenarbeit im Herbst', 'user_id' => 1, 'first_post_id' => 3, 'last_post_id' => 3, 'comment_count' => 1, 'created_at' => $at, 'last_posted_at' => $at],
                ['id' => 3, 'title' => 'Versteckt', 'user_id' => 1, 'first_post_id' => 4, 'last_post_id' => 4, 'comment_count' => 1, 'hidden_at' => $at, 'created_at' => $at, 'last_posted_at' => $at],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Sie liegt den ganzen Tag auf dem Sofa.</p></t>', 'created_at' => $at],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Unsere Hunde machen das auch, mit Begeisterung.</p></t>', 'created_at' => $at],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Die Rosen schneiden wir im Oktober.</p></t>', 'created_at' => $at],
                ['id' => 4, 'discussion_id' => 3, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Katzen und Hunde.</p></t>', 'created_at' => $at],
            ],
        ]);

        $this->deleteIndices();
    }

    protected function tearDown(): void
    {
        if (getenv('SEARCH_TEST_ENDPOINT')) {
            $this->deleteIndices();
        }

        parent::tearDown();
    }

    /** @test */
    public function a_first_build_makes_titles_and_posts_searchable()
    {
        $this->build();

        $this->assertSame(['1'], $this->searchIds('Katze'), 'title');
        $this->assertSame(['2'], $this->searchIds('Rosen'), 'first post');
        $this->assertSame(['1'], $this->searchIds('Begeisterung'), 'reply');
        $this->assertSame([], $this->searchIds('Versteckt'), 'a hidden discussion is not indexed');
        $this->assertSame('v5', $this->settingValue('blomstra-search.index-compatible'), 'no "reindex required" after a first build');
    }

    /** @test */
    public function the_german_analyzer_matches_inflected_forms()
    {
        // Through the repository, as the admin page saves it: settings are cached at boot, so a
        // row written with prepareDatabase() would not be seen.
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)
            ->set('blomstra-search.analyzer-language', 'german');

        $this->build();

        $this->assertSame('german', $this->settingValue('blomstra-search.indexed-analyzer'));
        $this->assertSame(['1'], $this->searchIds('Katzen'), 'Katzen finds Katze');
        $this->assertSame(['1'], $this->searchIds('Hund'), 'Hund finds Hunde');
    }

    /** @test */
    public function the_matched_reply_is_the_most_relevant_post()
    {
        $this->build();

        $discussion = $this->search('Begeisterung')[0];

        $this->assertSame('2', Arr::get($discussion, 'relationships.mostRelevantPost.data.id'));
    }

    /** @test */
    public function a_rebuild_swaps_the_alias_and_a_rollback_swaps_it_back()
    {
        $this->build();
        $first = $this->aliasTarget();

        // Concrete indices are named to the second; a real rebuild never starts within one.
        sleep(1);
        $this->build(['--keep-backup' => true]);
        $second = $this->aliasTarget();

        $this->assertNotSame($first, $second, 'the rebuild promoted a new index');
        $this->assertSame($first, $this->settingValue('blomstra-search.backup-index'));
        $this->assertSame(['1'], $this->searchIds('Katze'), 'search works on the promoted index');

        $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'rollback']);
        $this->refresh();

        $this->assertSame($first, $this->aliasTarget(), 'the rollback restored the first index');
        $this->assertSame(['1'], $this->searchIds('Katze'), 'search works after the rollback');
    }

    /** @test */
    public function fill_adds_what_the_index_is_missing()
    {
        $this->build();

        // Written straight to the database, so no event queued it for indexing. The discussion
        // first, then its post, then the link between them, as the foreign keys require.
        $at = Carbon::now();
        $this->database()->table('discussions')->insert(['id' => 4, 'title' => 'Fahrrad reparieren', 'user_id' => 1, 'comment_count' => 1, 'created_at' => $at, 'last_posted_at' => $at]);
        $this->database()->table('posts')->insert(['id' => 5, 'discussion_id' => 4, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Der Schlauch ist kaputt.</p></t>', 'created_at' => $at]);
        $this->database()->table('discussions')->where('id', 4)->update(['first_post_id' => 5, 'last_post_id' => 5]);

        $this->assertSame([], $this->searchIds('Fahrrad'));

        $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'fill']);
        $this->refresh();

        $this->assertSame(['4'], $this->searchIds('Fahrrad'));
    }

    /** @test */
    public function a_reply_through_the_api_is_indexed_live()
    {
        $this->build();

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['content' => 'Ein Wellensittich ist eingezogen.'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->refresh();

        $this->assertSame(['2'], $this->searchIds('Wellensittich'));
    }

    private function build(array $options = []): void
    {
        $output = $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'build'] + $options);

        $this->assertTrue(
            $this->client()->indices()->existsAlias(AliasLookup::params($this->alias)),
            "the build left no alias. Its output:\n$output"
        );
        $this->refresh();
    }

    /**
     * @return string[] discussion ids, in result order
     */
    private function searchIds(string $q): array
    {
        return Arr::pluck($this->search($q), 'id');
    }

    private function search(string $q): array
    {
        $response = $this->send(
            $this->request('GET', '/api/blomstra/search/discussions', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['q' => $q], 'include' => 'mostRelevantPost'])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function aliasTarget(): string
    {
        return array_keys($this->client()->indices()->getAlias(AliasLookup::params($this->alias)))[0];
    }

    private function refresh(): void
    {
        $this->client()->indices()->refresh(['index' => $this->alias]);
    }

    private function settingValue(string $key): ?string
    {
        return $this->database()->table('settings')->where('key', $key)->value('value');
    }

    private function client(): Client
    {
        return $this->app()->getContainer()->make(Client::class);
    }

    /**
     * By name, not by wildcard: a wildcard delete is refused where destructive actions require
     * explicit names.
     */
    private function deleteIndices(): void
    {
        foreach (array_keys($this->client()->indices()->get(['index' => "$this->alias*", 'ignore_unavailable' => true])) as $index) {
            $this->client()->indices()->delete(['index' => $index]);
        }
    }
}
