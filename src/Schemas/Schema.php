<?php

namespace Blomstra\Search\Schemas;

use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Events\Dispatcher;

abstract class Schema
{
    protected function extensionEnabled(string $extension): bool
    {
        /** @var ExtensionManager $manager */
        $manager = resolve(ExtensionManager::class);

        return $manager->isEnabled($extension);
    }

    abstract public static function index(): string;

    abstract public static function model(): string;

    abstract public static function savingOn(Dispatcher $events, callable $callable);
    abstract public static function deletingOn(Dispatcher $events, callable $callable);
}
