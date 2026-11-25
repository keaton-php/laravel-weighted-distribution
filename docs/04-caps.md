<!-- nav-group: Basic usage -->
# Caps

A cap limits a target to a number of picks per window of time. A target that reaches its cap is skipped until its window rolls over, and the other targets share its traffic by their weights.

Caps need to know the time. By default a distribution uses PHP's `time()`. The examples on this page pass a `clock` so they print the same output on every run.

## Adding a cap

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\WeightedDistribution;

$pages = WeightedDistribution::make('homepage', clock: fn () => strtotime('2026-03-02 09:00:00 UTC'))
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2, Cap::perDay(3));

$visits = [];

for ($visitor = 1; $visitor <= 20; $visitor++) {
    $decision = $pages->allocate();
    $visits[] = $decision->target;
}

foreach (['new-home', 'old-home', 'experiment'] as $page) {
    echo $page, ': ', array_count_values($visits)[$page] ?? 0, PHP_EOL;
}

echo PHP_EOL, $decision->explain(), PHP_EOL;
```

```text
new-home: 11
old-home: 6
experiment: 3

Allocation #20: 'new-home' was chosen. It was owed 0.75 of an allocation (slot 6 of 8 in this cycle) and was the only target due one. Not due one yet: old-home. At their cap: experiment (3/3 until 2026-03-03 00:00:00 UTC).
```

Without the cap the experiment would have had 4 of the 20 picks. It stopped at 3, and the other two targets took the rest in their own 5:3 ratio.

You can create a cap with `Cap::perMinute($limit)`, `Cap::perHour()`, `Cap::perDay()` or `Cap::perWeek()`, or with `Cap::per($limit, $seconds)` for any other window.

## Windows roll over

Windows are fixed and aligned to the clock, not to the first pick. A day runs from 00:00 to 24:00 UTC. In this example the clock is a variable that the code moves forward. `report()->usage('experiment')->remaining()` returns how many picks are left in the current window.

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\WeightedDistribution;

$now = strtotime('2026-03-02 23:58:00 UTC');

$pages = WeightedDistribution::make('homepage', clock: function () use (&$now) {
    return $now;
})
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2, Cap::perDay(1));

for ($visitor = 1; $visitor <= 4; $visitor++) {
    echo $pages->allocate()->target, PHP_EOL;
}

echo 'experiment: ', $pages->report()->usage('experiment')->remaining(), ' left today', PHP_EOL;

$now += 120;                    // 00:00 UTC on March 3: a new day, a new window

echo 'experiment: ', $pages->report()->usage('experiment')->remaining(), ' left today', PHP_EOL;
```

```text
new-home
old-home
new-home
experiment
experiment: 0 left today
experiment: 1 left today
```

## When every target is full

A target without a cap can always take a pick. When every target has a cap and all of them are full, `hasCapacity()` returns `false`, and `allocate()` and `peek()` throw `NoTargetAvailable`.

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\Exceptions\NoTargetAvailable;
use Keaton\WeightedDistribution\WeightedDistribution;

$pages = WeightedDistribution::make('homepage', clock: fn () => strtotime('2026-03-02 09:00:00 UTC'))
    ->target('old-home', 3, Cap::perDay(1))
    ->target('experiment', 2, Cap::perDay(1));

$pages->allocate();
$pages->allocate();

var_export($pages->hasCapacity());
echo PHP_EOL;

try {
    $pages->allocate();
} catch (NoTargetAvailable $e) {
    echo $e->getMessage(), PHP_EOL;
}
```

```text
false
Every target is at its cap (old-home, experiment); the earliest window resets at 2026-03-03 00:00:00 UTC.
```

**Next:** [Recipes](05-recipes.md).
