<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Distribution name
    |--------------------------------------------------------------------------
    |
    | The key the distribution's state is saved under. Two distributions that
    | share a store must have different names.
    |
    */
    'name' => env('WEIGHTED_DISTRIBUTION_NAME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Where the deficits, totals and cap usage live.
    |
    |   'array'      The memory of one PHP process. Fine for tests and one-off
    |                scripts. Under PHP-FPM each request starts from zero; a
    |                queue worker or an Octane worker keeps its own history for
    |                as long as it runs, separate from every other worker.
    |   'cache'      A Laravel cache store (see the 'cache' block below). Use
    |                this in production, with a cache every worker reaches, so
    |                they all share one state.
    |   a class      The name of any class implementing the Store contract,
    |                resolved through the container. A database store, for
    |                example: App\Distribution\DatabaseStore::class.
    |
    */
    'store' => env('WEIGHTED_DISTRIBUTION_STORE', 'array'),

    'cache' => [
        // Cache store name from config/cache.php; null uses the default store.
        // It must support atomic locks, and every worker must reach the same
        // backend: redis, memcached, database or dynamodb across servers; file
        // only when every worker shares that filesystem; array only in one process.
        'store' => env('WEIGHTED_DISTRIBUTION_CACHE_STORE'),

        'prefix' => 'weighted-distribution',

        // How long a held lock may live, and how long allocate() or reset() waits for it.
        'lock_seconds' => 10,
        'lock_wait' => 5,

        // Seconds to keep the state; null keeps it until you call reset().
        'ttl' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    |
    | The default distribution. Weights are positive integers: 5 and 3 means
    | five of every eight allocations go to the first. A cap is optional;
    | 'per' takes second, minute, hour, day, week or a number of seconds.
    |
    |   'targets' => [
    |       'alpha' => ['weight' => 5],
    |       'beta' => ['weight' => 3, 'cap' => ['limit' => 100, 'per' => 'day']],
    |       'gamma' => ['weight' => 2],
    |   ],
    |
    */
    'targets' => [],

];
