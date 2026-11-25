<!-- nav-group: Advanced -->
# In a Laravel app

## The config file

Publish the config file with `php artisan vendor:publish --tag="weighted_distribution-config"` and describe your distribution. Settings you leave out keep their defaults.

```php
<?php

return [

    'name' => 'homepage',

    // Requests and workers share one state, as long as they all reach the same cache.
    'store' => env('WEIGHTED_DISTRIBUTION_STORE', 'cache'),

    'cache' => [
        'store' => env('WEIGHTED_DISTRIBUTION_CACHE_STORE'),   // null picks Laravel's default cache store
    ],

    'targets' => [
        'new-home' => ['weight' => 5],
        'old-home' => ['weight' => 3],
        'experiment' => ['weight' => 2, 'cap' => ['limit' => 500, 'per' => 'day']],
    ],

];
```

## The facade

The facade resolves the distribution described in the config file. You can also inject the `WeightedDistribution` class. The container builds it once, so the facade and every injected instance are the same object.

```php
<?php

use Keaton\WeightedDistribution\Facades\WeightedDistribution;

$visits = [];

for ($visitor = 1; $visitor <= 10; $visitor++) {
    $visits[] = WeightedDistribution::allocate()->target;
}

echo implode(' ', $visits), PHP_EOL;
echo WeightedDistribution::report()->usage('experiment')->remaining(), ' experiment visits left today', PHP_EOL;
```

```text
new-home old-home new-home experiment new-home old-home new-home experiment old-home new-home
498 experiment visits left today
```

## Routing leads across queue workers

In this example three teams take leads in a 5:3:2 ratio. North is capped at 120 leads a day and west at 40. South has no cap, so a lead always has somewhere to go.

`LeadRouter` in [tests/Fixtures/DocsHelpers.php](https://github.com/keaton-php/laravel-weighted-distribution/blob/1.x/tests/Fixtures/DocsHelpers.php) wraps the distribution and returns each decision's `explain()` with the lead. If you save it, you can still answer "why did this lead go to west?" months later. Two queue workers share the state through the cache, so together they make the same 300 picks a single worker would.

```php
<?php

use Illuminate\Support\Facades\Cache;
use Keaton\WeightedDistribution\Stores\CacheStore;

$now = strtotime('2026-03-02 08:00:00 UTC');
$clock = function () use (&$now) {
    return $now;
};

$workers = [
    new LeadRouter(new CacheStore(Cache::store()), $clock),
    new LeadRouter(new CacheStore(Cache::store()), $clock),
];

$ledger = [];

for ($lead = 1; $lead <= 300; $lead++) {
    $ledger[] = $workers[$lead % 2]->route($lead);
    $now += 120;
}

echo implode(' ', array_column(array_slice($ledger, 0, 10), 'team')), ' ...', PHP_EOL, PHP_EOL;

foreach ($ledger as $entry) {
    if (str_contains($entry['why'], 'At their cap: west')) {
        echo "Lead {$entry['lead']}: {$entry['why']}", PHP_EOL, PHP_EOL;
        break;
    }
}

foreach ($workers[0]->report()->targets as $team) {
    printf(
        "%-6s  weight %d  got %3d of 300  %s\n",
        $team->target,
        $team->weight,
        $team->allocated,
        $team->limit === null ? 'no cap' : ($team->saturated ? "full for today ({$team->used}/{$team->limit})" : "{$team->used}/{$team->limit} today"),
    );
}

$now = strtotime('2026-03-03 08:00:00 UTC');          // the next morning, both caps are fresh

echo PHP_EOL, 'Lead 301 goes to ', $workers[1]->route(301)['team'], PHP_EOL;
```

```text
north south north west north south north west south north ...

Lead 199: Allocation #199: 'north' was chosen. It was owed 0.62 of an allocation (slot 1 of 8 in this cycle) and was the only target due one. Not due one yet: south. At their cap: west (40/40 until 2026-03-03 00:00:00 UTC).

north   weight 5  got 120 of 300  full for today (120/120)
south   weight 3  got 140 of 300  no cap
west    weight 2  got  40 of 300  full for today (40/40)

Lead 301 goes to north
```

**Next:** [Reference](09-reference.md).
