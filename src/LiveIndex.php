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

namespace Blomstra\Search;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * The concrete index searches and live updates go to, recorded in a setting. No alias: on a
 * server shared between forums, alias changes are authorized on the index, never on the alias
 * name, so a forum allowed to change aliases could take over another forum's.
 */
class LiveIndex
{
    public const SETTING = 'blomstra-search.active-index';

    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Null before the first build.
     *
     * From the table, not the settings repository: that caches for the life of the process, and
     * a queue worker must follow a promote done by another process.
     */
    public function name(): ?string
    {
        return $this->db->table('settings')->where('key', self::SETTING)->value('value') ?: null;
    }

    /**
     * Make $index live. Returns the index it replaced.
     */
    public function switchTo(string $index): ?string
    {
        $previous = $this->name();

        $this->settings->set(self::SETTING, $index);

        return $previous === $index ? null : $previous;
    }
}
