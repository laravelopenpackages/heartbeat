<?php

namespace LaravelOpenPackages\Heartbeat;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LaravelOpenPackages\Heartbeat\Http\Controllers\BeatController;

class HeartbeatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/heartbeat.php', 'heartbeat');

        // One instance per worker: it holds the watchers registered at boot. It reads the
        // config once and never holds the request, so it's safe under Octane.
        $this->app->singleton(Heartbeat::class, fn (Application $app) => new Heartbeat(
            $app['cache'],
            (array) $app['config']->get('heartbeat', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/heartbeat.php' => config_path('heartbeat.php')], 'heartbeat-config');
            $this->publishes([__DIR__ . '/../resources/js' => resource_path('js/vendor/heartbeat')], 'heartbeat-js');
        }

        $route = (array) config('heartbeat.route', []);
        if (($route['enabled'] ?? true) && ! $this->app->routesAreCached()) {
            Route::middleware($route['middleware'] ?? ['web', 'auth'])
                ->get($route['path'] ?? 'heartbeat', BeatController::class)
                ->name($route['name'] ?? 'heartbeat');
        }
    }
}
