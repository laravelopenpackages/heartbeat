<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The beat endpoint
    |--------------------------------------------------------------------------
    |
    | GET {path}?since=<ms>&topics=a,b — answers which of those topics changed
    | since the client's last beat. Put it behind your app's auth so each user
    | only learns about their own topics (and the global ones).
    |
    */

    'route' => [
        'enabled' => true,
        'path' => 'heartbeat',
        'middleware' => ['web', 'auth'],
        'name' => 'heartbeat',
    ],

    /*
    |--------------------------------------------------------------------------
    | How often clients beat (seconds)
    |--------------------------------------------------------------------------
    |
    | Sent with every answer, so you can slow every open page down (or speed it
    | up) from here without shipping new JavaScript.
    |
    */

    'interval' => (int) env('HEARTBEAT_INTERVAL', 15),

    /*
    |--------------------------------------------------------------------------
    | Where "last changed" marks are kept
    |--------------------------------------------------------------------------
    |
    | A cache store every web server shares (redis, database, memcached…) — not
    | `array`, which forgets everything after each request. null = the default.
    | Marks older than `ttl` seconds are simply forgotten (nobody's still waiting).
    |
    */

    'cache' => [
        'store' => env('HEARTBEAT_CACHE_STORE'),
        'prefix' => 'heartbeat',
        'ttl' => 60 * 60 * 24 * 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_topics' => 50,

];
