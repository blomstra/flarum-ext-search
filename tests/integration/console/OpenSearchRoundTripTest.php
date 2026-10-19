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

use Blomstra\Search\Commands\BuildCommand;
use Blomstra\Search\Exceptions\SeedingException;
use Blomstra\Search\Jobs\DeletingJob;
use Blomstra\Search\Jobs\PromoteIndexJob;
use Blomstra\Search\LiveIndex;
use Blomstra\Search\Seeders\DiscussionSeeder;
use Carbon\Carbon;
use Elasticsearch\Client;
use Flarum\Discussion\Discussion;
use Flarum\Settings\DatabaseSettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Psr\Log\NullLogger;

/**
 * The index lifecycle and search against a real search server, through the connection from
 * config.php and with the credentials a host would hand a forum: a user whose role covers only
 * `<index>_*`. Skipped unless SEARCH_TEST_ENDPOINT, SEARCH_TEST_USERNAME, SEARCH_TEST_PASSWORD and
 * SEARCH_TEST_INDEX are set. Give it a user without alias permissions, so any alias call fails a
 * test. Every index under that prefix is deleted before each test.
 *
 * Flarum's default queue is synchronous, so a build's jobs (seeding, promotion) have all run by
 * the time the command returns.
 */
class OpenSearchRoundTripTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('SEARCH_TEST_ENDPOINT')) {
            $this->markTestSkipped('No search server: set SEARCH_TEST_ENDPOINT, SEARCH_TEST_USERNAME, SEARCH_TEST_PASSWORD and SEARCH_TEST_INDEX.');
        }

        $this->prefix = (string) getenv('SEARCH_TEST_INDEX');

        $this->extension('blomstra-search');
        $this->config('search', [
            'endpoint' => getenv('SEARCH_TEST_ENDPOINT'),
            'username' => getenv('SEARCH_TEST_USERNAME'),
            'password' => getenv('SEARCH_TEST_PASSWORD'),
            'index'    => $this->prefix,
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
    public function a_rebuild_promotes_a_new_index_and_a_rollback_restores_the_old_one()
    {
        $this->build();
        $first = $this->liveIndex();

        // Concrete indices are named to the second; a real rebuild never starts within one.
        sleep(1);
        $this->build(['--keep-backup' => true]);
        $second = $this->liveIndex();

        $this->assertNotSame($first, $second, 'the rebuild promoted a new index');
        $this->assertSame($first, $this->settingValue('blomstra-search.backup-index'));
        $this->assertSame(['1'], $this->searchIds('Katze'), 'search works on the promoted index');

        $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'rollback']);
        $this->refresh();

        $this->assertSame($first, $this->liveIndex(), 'the rollback restored the first index');
        $this->assertFalse($this->client()->indices()->exists(['index' => $second]), 'the rollback deleted the second index');
        $this->assertSame(['1'], $this->searchIds('Katze'), 'search works after the rollback');
    }

    /** @test */
    public function a_staging_build_goes_live_on_promote()
    {
        $this->build();
        $first = $this->liveIndex();

        sleep(1);
        $this->build(['--staging' => true]);
        $staging = $this->settingValue('blomstra-search.staging-index');
        $this->assertSame($first, $this->liveIndex(), 'a staging build leaves the live index alone');

        $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'promote', '--i-am-sure' => true]);
        $this->refresh();

        $this->assertSame($staging, $this->liveIndex());
        $this->assertNull($this->settingValue('blomstra-search.staging-index'));
        $this->assertFalse($this->client()->indices()->exists(['index' => $first]), 'without --keep-backup the old index is deleted');
        $this->assertSame(['1'], $this->searchIds('Katze'));
        $this->assertNoAliases();
    }

    /** @test */
    public function discard_drops_a_staging_build()
    {
        $this->build();
        $first = $this->liveIndex();

        sleep(1);
        $this->build(['--staging' => true]);
        $staging = $this->settingValue('blomstra-search.staging-index');

        $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'discard', '--pending' => true]);

        $this->assertFalse($this->client()->indices()->exists(['index' => $staging]));
        $this->assertNull($this->settingValue('blomstra-search.staging-index'));
        $this->assertSame($first, $this->liveIndex());
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
    public function a_build_seeds_past_an_id_gap_down_to_id_1()
    {
        // Discussion 5001 leaves no seedable discussion in the range below it; post 2501 makes
        // the range of posts below it start at 2, leaving only id 1 for the last range.
        $at = Carbon::now();
        $this->database()->table('discussions')->insert(['id' => 5001, 'title' => 'Fahrrad reparieren', 'user_id' => 1, 'comment_count' => 1, 'created_at' => $at, 'last_posted_at' => $at]);
        $this->database()->table('posts')->insert(['id' => 2501, 'discussion_id' => 5001, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Der Schlauch ist kaputt.</p></t>', 'created_at' => $at]);
        $this->database()->table('discussions')->where('id', 5001)->update(['first_post_id' => 2501, 'last_post_id' => 2501]);

        $this->build();

        $this->assertSame(['5001'], $this->searchIds('Schlauch'));
        $this->assertSame(['1'], $this->searchIds('Katze'), 'a discussion below the gap');
        $this->assertSame(['1'], $this->searchIds('Sofa'), 'post 1');
    }

    /** @test */
    public function fill_queues_nothing_when_a_full_range_is_indexed()
    {
        $at = Carbon::now();
        $posts = [];
        for ($id = 5; $id <= BuildCommand::BATCH_SIZE + 4; $id++) {
            $posts[] = ['id' => $id, 'discussion_id' => 2, 'number' => $id, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Antwort</p></t>', 'created_at' => $at];
        }
        foreach (array_chunk($posts, 500) as $chunk) {
            $this->database()->table('posts')->insert($chunk);
        }

        $this->build();

        $output = $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'fill']);

        $this->assertStringContainsString('Queued a total of 0 discussions', $output);
        $this->assertStringContainsString('Queued a total of 0 posts', $output);
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

    /** @test */
    public function hiding_a_discussion_removes_it_from_the_index()
    {
        $this->build();
        $this->assertTrue($this->indexed('discussions:2'));

        $response = $this->send($this->request('PATCH', '/api/discussions/2', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['isHidden' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // From the index itself: the visibility check would drop it from results anyway.
        $this->assertFalse($this->indexed('discussions:2'));
    }

    /** @test */
    public function a_worker_writes_to_the_index_another_process_promoted()
    {
        $this->build();
        $old = $this->liveIndex();

        sleep(1);
        $this->build(['--staging' => true]);

        // This process has cached its settings; the promote runs as another process would, with
        // settings of its own.
        $this->assertSame($old, $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->get(LiveIndex::SETTING));
        $this->promoteElsewhere();

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['content' => 'Ein Wellensittich ist eingezogen.'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->refresh();

        $this->assertSame(['2'], $this->searchIds('Wellensittich'));
        $this->assertFalse($this->client()->indices()->exists(['index' => $old]), 'the reply did not recreate the old index');
    }

    /** @test */
    public function a_delete_that_raced_a_promote_fails_for_a_retry()
    {
        $this->build();

        // The live index as a promote leaves it for a job that read the setting just before.
        $this->client()->indices()->delete(['index' => $this->liveIndex()]);

        $job = new DeletingJob(Collection::make([Discussion::find(2)]), $this->app()->getContainer()->make(DiscussionSeeder::class));

        $this->expectException(SeedingException::class);
        $job->handle($this->client(), $this->app()->getContainer()->make(LiveIndex::class));
    }

    private function build(array $options = []): void
    {
        $output = $this->runCommand(['command' => 'blomstra:search:index', 'action' => 'build'] + $options);

        $this->assertTrue(
            $this->client()->indices()->exists(['index' => $this->liveIndex()]),
            "the build left no live index. Its output:\n$output"
        );
        $this->assertNoAliases();
        $this->refresh();
    }

    private function promoteElsewhere(): void
    {
        $settings = new DatabaseSettingsRepository($this->database());

        (new PromoteIndexJob())->handle($this->client(), $settings, new LiveIndex($this->database(), $settings), new NullLogger());
    }

    private function assertNoAliases(): void
    {
        foreach ($this->client()->indices()->get(['index' => "{$this->prefix}_*"]) as $index => $definition) {
            $this->assertEmpty($definition['aliases'], "'$index' has an alias");
        }
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

    private function liveIndex(): string
    {
        return (string) $this->settingValue(LiveIndex::SETTING);
    }

    private function indexed(string $id): bool
    {
        return $this->client()->exists(['index' => $this->liveIndex(), 'id' => $id, 'routing' => explode(':', $id)[1]]);
    }

    private function refresh(): void
    {
        $this->client()->indices()->refresh(['index' => "{$this->prefix}_*"]);
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
        foreach (array_keys($this->client()->indices()->get(['index' => "{$this->prefix}_*", 'ignore_unavailable' => true])) as $index) {
            $this->client()->indices()->delete(['index' => $index]);
        }
    }
}
