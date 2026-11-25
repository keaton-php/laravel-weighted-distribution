<!-- nav-group: Reference -->
# Reference

## How it works

A cycle lasts as many picks as the weights add up to, after dividing out their common factor. During a cycle each target builds up a deficit, which is the number of picks it should have had by now minus the number it has had.

With `m` targets in play, a target is due a pick once it is owed at least `1 / (2(m - 1))` of one. Of the targets that are due, the one whose next pick falls due soonest wins. On a tie the target that was registered first wins. A lone target gets every pick.

This is the rule R. Tijdeman published in 1980 for the chairman assignment problem. It guarantees that after every pick each target is within `1 - 1/(2(m - 1))` of its exact count. All comparisons use integer arithmetic, so there are no rounding errors.

## Invalid definitions

`targets()` takes the same array as the config file. An invalid definition throws an exception that names the config key. This covers a blank or duplicate name, a weight or cap below 1, an unknown window, an unknown key and a value of the wrong type.

```php
<?php

use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\WeightedDistribution;

try {
    WeightedDistribution::make('homepage')->targets([
        'new-home' => ['weight' => 5],
        'experiment' => ['weight' => 2, 'capp' => ['limit' => 500, 'per' => 'day']],
    ]);
} catch (InvalidConfiguration $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

```text
The [weighted_distribution.targets.experiment] definition has unknown keys [capp]; a target takes [weight] and an optional [cap].
```

## Methods

| Method | What it does |
|---|---|
| `make(string $name = 'default', ?Store $store = null, ?Closure $clock = null)` | Creates a distribution. The store defaults to a new `ArrayStore`. `$clock` returns a Unix timestamp and defaults to `time()`. |
| `target(string $name, int $weight, ?Cap $cap = null)` | Registers a target. Returns the distribution. |
| `targets(array $definitions)` | Registers several targets, as `['name' => ['weight' => 5, 'cap' => ['limit' => 100, 'per' => 'day']]]`. |
| `allocate()` | Picks the next target and records the pick. Returns a `Decision`. |
| `peek()` | Returns the `Decision` that `allocate()` would make now, without recording it. |
| `hasCapacity()` | `true` while at least one target is below its cap. |
| `report()` | Returns a `Report` of lifetime totals and cap usage. |
| `reset()` | Clears everything the store holds for this distribution. Returns the distribution. |

The facade forwards these methods to the distribution built from the config file. That instance is shared for the life of the app or worker, so define its targets in the config file and not with `target()` at runtime.

## Decision, Standing, Report and Usage

| Object | Properties and methods |
|---|---|
| `Decision` | `target`, `reason`, `sequence` (the lifetime number of the pick), `position` and `cycle` (its slot and the length of the cycle), `at` (the clock's time), `standings` (a `Standing` per target, keyed by name), `standing()` (the picked target's), `explain()`, `toArray()` |
| `Standing` | `target`, `weight`, `status`, `share` (among the targets not at their cap), `allocated` (picks this cycle, before this one), `owed` (negative when ahead), and `used`, `limit` and `resetsAt` for a capped target |
| `Report` | `allocated` (the lifetime total), `usage($target)`, `isSaturated($target)`, `saturated()`, `available()` |
| `Usage` | `allocated`, `share`, `actual`, `used`, `limit`, `resetsAt`, `saturated`, `remaining()` |

A target at its cap is not part of the cycle, so its `share`, `allocated` and `owed` are 0. `toArray()` returns `standings` as a list in registration order.

## Configuration

| Key | Default | What it does |
|---|---|---|
| `name` | `default` | The key the state is saved under. Env `WEIGHTED_DISTRIBUTION_NAME`. |
| `store` | `array` | `array`, `cache`, or a `Store` class name. Env `WEIGHTED_DISTRIBUTION_STORE`. |
| `cache.store` | `null` | A cache store from `config/cache.php`. `null` uses the default store. Env `WEIGHTED_DISTRIBUTION_CACHE_STORE`. |
| `cache.prefix` | `weighted-distribution` | The cache key prefix: 1 to 64 letters, digits, dots, colons, dashes or underscores. |
| `cache.lock_seconds` | `10` | How long a held lock lives. |
| `cache.lock_wait` | `5` | How long `allocate()` or `reset()` waits for the lock before throwing `StoreFailure`. |
| `cache.ttl` | `null` | Seconds to keep the state. `null` keeps it until `reset()`. |
| `targets` | `[]` | `name => ['weight' => int, 'cap' => ['limit' => int, 'per' => …]]`, where `per` is `second`, `minute`, `hour`, `day`, `week` or a number of seconds. |

All keys are under `weighted_distribution.`. An unknown key throws `InvalidConfiguration`.

## Exceptions

All exceptions are in the `Keaton\WeightedDistribution\Exceptions` namespace.

| Exception | Thrown when |
|---|---|
| `NoTargetAvailable` | There are no targets, or every target is at its cap. |
| `InvalidTarget` | A target has an invalid name or weight, or a report lookup names an unknown target. |
| `InvalidCap` | A cap has an invalid limit, window or key. |
| `InvalidConfiguration` | The config has an unknown key, a value of the wrong type or an invalid store setting. |
| `StoreFailure` | A lock can't be obtained, the cache won't save or delete, or the saved state is invalid. Call `reset()` to clear it. |
