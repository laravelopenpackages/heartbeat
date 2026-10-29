<?php

namespace LaravelOpenPackages\Heartbeat\Tests;

use Illuminate\Auth\GenericUser;
use LaravelOpenPackages\Heartbeat\HeartbeatServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HeartbeatServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // `array` lives as long as the app does — one test — which is all a test needs.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('heartbeat.route.middleware', ['auth']);
    }

    protected function user(int $id): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => "User {$id}"]);
    }
}
