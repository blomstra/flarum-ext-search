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

namespace Blomstra\Search\Seeders;

use Blomstra\Search\Save\Document;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class Seeder
{
    abstract public function type(): string;

    /**
     * The join relation name for this document type ('discussion' or 'post').
     * Used in the join_field mapping and for filtering in seed-missing checks.
     */
    abstract public function joinRelation(): string;

    /**
     * The ES routing key for this model. Parent and child documents for the same
     * discussion must share a routing key so ES places them on the same shard.
     */
    abstract public function routing(Model $model): string;

    abstract public function query(): Builder;

    abstract public static function savingOn(Dispatcher $events, callable $callable);

    abstract public static function deletingOn(Dispatcher $events, callable $callable);

    /** No-op default; override in seeders that need to react to view events. */
    public static function viewingOn(Dispatcher $events, callable $callable): void {}

    abstract public function toDocument(Model $model): Document;

    protected function extensionEnabled(string $extension): bool
    {
        /** @var ExtensionManager $manager */
        $manager = resolve(ExtensionManager::class);

        return $manager->isEnabled($extension);
    }
}
