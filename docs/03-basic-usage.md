<!-- nav-group: Basic usage -->
# Basic usage

`make()` creates a distribution, `target()` adds a target with its weight, and `allocate()` picks the next target. Weights are whole numbers. With weights of `5`, `3` and `2`, five of every ten picks go to the first target.

## An exact split

Say you're testing a new homepage. Half your visitors should see it, three in ten should see the old page, and two in ten should see an experiment.

```php
<?php

use Keaton\WeightedDistribution\WeightedDistribution;

$pages = WeightedDistribution::make('homepage')
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2);

$visits = [];

for ($visitor = 1; $visitor <= 10; $visitor++) {
    $visits[] = $pages->allocate()->target;
}

foreach (['new-home', 'old-home', 'experiment'] as $page) {
    echo $page, ': ', array_count_values($visits)[$page] ?? 0, PHP_EOL;
}

echo PHP_EOL, implode(' ', $visits), PHP_EOL;
```

```text
new-home: 5
old-home: 3
experiment: 2

new-home old-home new-home experiment new-home old-home new-home experiment old-home new-home
```

Ten picks come out as exactly 5, 3 and 2, and each target's picks are spread through the run. The groups are the right size from the first visitor, so you don't have to wait for the averages to settle.

## Exact at every step

A weighted random pick is right over a long run, but it drifts on the way there. This example sends 1,000 visitors through a random pick and 1,000 through the package. After every visitor it checks how far each page is from its exact share, and it prints the worst it saw.

```php
<?php

use Keaton\WeightedDistribution\WeightedDistribution;

$weights = ['new-home' => 5, 'old-home' => 3, 'experiment' => 2];

$worstDrift = function (Closure $pick) use ($weights): float {
    $seen = array_fill_keys(array_keys($weights), 0);
    $worst = 0.0;

    for ($visitor = 1; $visitor <= 1000; $visitor++) {
        $seen[$pick()]++;

        foreach ($weights as $page => $weight) {
            $worst = max($worst, abs($seen[$page] - $visitor * $weight / 10));
        }
    }

    return $worst;
};

$random = new Random\Randomizer(new Random\Engine\Mt19937(1));

$byHand = function () use ($random, $weights): string {
    $roll = $random->getInt(1, 10);

    foreach ($weights as $page => $weight) {
        if (($roll -= $weight) <= 0) {
            return $page;
        }
    }
};

$pages = WeightedDistribution::make('homepage')->targets([
    'new-home' => ['weight' => 5],
    'old-home' => ['weight' => 3],
    'experiment' => ['weight' => 2],
]);

echo 'random pick: off by up to ', $worstDrift($byHand), PHP_EOL;
echo 'package: off by up to ', $worstDrift(fn () => $pages->allocate()->target), PHP_EOL;
```

```text
random pick: off by up to 21.5
package: off by up to 0.6
```

After every pick, each target is less than one item away from its exact share. With two targets the bound is half an item, and with three it is three quarters.

The bound holds for as long as the same targets are available. `FairnessTest` checks it after every pick across 55 weight sets. The [reference](09-reference.md) explains the rule behind it.

**Next:** [Caps](04-caps.md).
