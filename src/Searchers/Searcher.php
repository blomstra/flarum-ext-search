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

namespace Blomstra\Search\Searchers;

use Blomstra\Search\Seeders\Seeder;
use Flarum\Settings\SettingsRepositoryInterface;

abstract class Searcher
{
    protected string|null $seeder = null;

    public function type(): string
    {
        /** @var Seeder $seeder */
        $seeder = $this->seeder;

        if (empty($seeder)) {
            throw new \InvalidArgumentException('Implement type or add $seeder');
        }

        return (new $seeder())->type();
    }

    public function enabled(): bool
    {
        return true;
    }

    public function boost(): float
    {
        return 1;
    }

    protected function setting(string $key, $default = null)
    {
        return resolve(SettingsRepositoryInterface::class)->get($key, $default);
    }
}
