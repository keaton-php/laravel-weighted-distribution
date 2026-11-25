<!-- nav-group: Advanced -->
# Stores

A distribution keeps its state in a store: its position in the cycle, the lifetime totals and the cap usage. The default `ArrayStore` keeps the state in memory for the current process. Two distributions with the same name on the same store share their state.

## Sharing a store

```php
<?php

use Keaton\WeightedDistribution\Stores\ArrayStore;
use Keaton\WeightedDistribution\WeightedDistribution;

$store = new ArrayStore;

// Two distribution objects, built separately, sharing one store.
$make = fn () => WeightedDistribution::make('homepage', $store)
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2);

$first = $make();
$second = $make();

$visits = [];

for ($visitor = 1; $visitor <= 10; $visitor++) {
    $visits[] = ($visitor % 2 === 1 ? $first : $second)->allocate()->target;
}

echo implode(' ', $visits), PHP_EOL;
```

```text
new-home old-home new-home experiment new-home old-home new-home experiment old-home new-home
```

The two objects take turns, but together they produce one sequence, because the state is saved under the distribution's name.

Queue workers are separate processes, so in production they need a store they can all reach. `CacheStore` keeps the state in a Laravel cache store and takes a lock around every pick, so two workers never start from the same state. The cache store has to support atomic locks. Redis, memcached, database and dynamodb work across servers, and the file driver works on a shared filesystem. [In a Laravel app](08-in-laravel.md) shows how to set it up.

## Writing your own store

A store implements `get()`, `update()` and `forget()`. `update()` must read the state, pass it to the callback and save the result as one atomic step. The state is made of plain arrays of strings and integers, so you can keep it in a cache, a database column or a file.

`FileStore` in [tests/Fixtures/DocsHelpers.php](https://github.com/keaton-php/laravel-weighted-distribution/blob/1.x/tests/Fixtures/DocsHelpers.php) is an example. It keeps each distribution in a JSON file under a file lock, and throws when the file can't be locked, read or written. Here a second distribution picks up where the first one stopped.

```php
<?php

use Keaton\WeightedDistribution\WeightedDistribution;

$store = new FileStore(sys_get_temp_dir());

$pages = WeightedDistribution::make('homepage-file-store', $store)
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2)
    ->reset();                  // start this example from nothing

$pages->allocate();
$pages->allocate();

// A second process would read the same file and carry on:
$again = WeightedDistribution::make('homepage-file-store', new FileStore(sys_get_temp_dir()))
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2);

echo $again->allocate()->sequence, PHP_EOL;
echo $again->report()->usage('new-home')->allocated, PHP_EOL;
```

```text
3
2
```

The first pick of the second distribution is the third of the run, and `new-home` has had two.

`get()` returns an array, or `null` when nothing has been saved. Saved state that is invalid throws `StoreFailure`, and `reset()` clears it.

**Next:** [In a Laravel app](08-in-laravel.md).
