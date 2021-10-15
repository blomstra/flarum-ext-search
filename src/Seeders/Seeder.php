<?php

namespace Blomstra\Search\Seeders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

abstract class Seeder
{
    abstract public function type(): string;

    abstract public function query(): Builder;

    abstract public static function savingOn(Dispatcher $events, callable $callable);
    abstract public static function deletingOn(Dispatcher $events, callable $callable);
}
