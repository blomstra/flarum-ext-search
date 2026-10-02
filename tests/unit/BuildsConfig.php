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

use Flarum\Foundation\Config;
use Flarum\Settings\OverrideSettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Settings\UninstalledSettingsRepository;

/**
 * config.php and the settings table, without a database.
 */
trait BuildsConfig
{
    private function config(?array $search = null): Config
    {
        $data = ['url' => 'https://forum.example'];

        if ($search !== null) {
            $data['search'] = $search;
        }

        return new Config($data);
    }

    private function settings(array $values): SettingsRepositoryInterface
    {
        return new OverrideSettingsRepository(new UninstalledSettingsRepository(), $values);
    }
}
