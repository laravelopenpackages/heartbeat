<?php

namespace LaravelOpenPackages\Heartbeat\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void touch(string|list<string> $topics, \Illuminate\Contracts\Auth\Authenticatable|int|string|null $for = null)
 * @method static void watch(string $topic, \Closure $changedSince)
 * @method static list<string> changedSince(int $since, list<string> $topics, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, ?int $now = null)
 * @method static int now()
 * @method static int interval()
 * @method static list<string> watched()
 *
 * @see \LaravelOpenPackages\Heartbeat\Heartbeat
 */
class Heartbeat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelOpenPackages\Heartbeat\Heartbeat::class;
    }
}
