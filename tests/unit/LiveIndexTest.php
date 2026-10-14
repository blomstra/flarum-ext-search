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

namespace Blomstra\Search\Tests\unit;

use Blomstra\Search\LiveIndex;
use Flarum\Settings\DatabaseSettingsRepository;
use Flarum\Settings\MemoryCacheSettingsRepository;
use Flarum\Testing\unit\TestCase;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * Against a settings table in SQLite.
 */
class LiveIndexTest extends TestCase
{
    private SQLiteConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $this->db->getSchemaBuilder()->create('settings', function ($table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    /** @test */
    public function the_name_is_the_active_index()
    {
        $this->assertNull($this->liveIndex()->name(), 'before the first build');

        $this->db->table('settings')->insert(['key' => LiveIndex::SETTING, 'value' => 'x1_20260101000000']);

        $this->assertSame('x1_20260101000000', $this->liveIndex()->name());
    }

    /** @test */
    public function a_switch_records_the_index_and_returns_the_one_it_replaced()
    {
        $this->assertNull($this->liveIndex()->switchTo('x1_old'), 'nothing replaced on a first build');
        $this->assertSame('x1_old', $this->liveIndex()->switchTo('x1_new'));
        $this->assertSame('x1_new', $this->db->table('settings')->where('key', LiveIndex::SETTING)->value('value'));
    }

    /** @test */
    public function switching_to_the_live_index_replaces_nothing()
    {
        $this->liveIndex()->switchTo('x1_live');

        $this->assertNull($this->liveIndex()->switchTo('x1_live'), 'otherwise a promote or rollback deletes the live index');
    }

    /** @test */
    public function a_worker_follows_a_promote_done_by_another_process()
    {
        $this->db->table('settings')->insert(['key' => LiveIndex::SETTING, 'value' => 'x1_old']);

        $workerSettings = new MemoryCacheSettingsRepository(new DatabaseSettingsRepository($this->db));
        $worker = new LiveIndex($this->db, $workerSettings);
        $this->assertSame('x1_old', $worker->name());
        $this->assertSame('x1_old', $workerSettings->get(LiveIndex::SETTING), 'the worker has cached its settings');

        $this->liveIndex()->switchTo('x1_new');

        $this->assertSame('x1_new', $worker->name());
    }

    private function liveIndex(): LiveIndex
    {
        return new LiveIndex($this->db, new DatabaseSettingsRepository($this->db));
    }
}
