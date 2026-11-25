<!-- nav-group: Start here -->
# Introduction

Laravel Weighted Distribution hands things out in a fixed ratio, one at a time. It doesn't roll dice, so the ratio is exact after every pick and not only on average.

## Before and after

Say three sales teams share your leads in a 5:3:2 ratio. The usual way to do that is a weighted random pick:

```php
<?php

$teams = ['north' => 5, 'south' => 3, 'west' => 2];

// a fixed seed, so this prints the same ten picks on every run
$random = new Random\Randomizer(new Random\Engine\Mt19937(1));

$leads = [];

for ($lead = 1; $lead <= 10; $lead++) {
    $roll = $random->getInt(1, 10);

    foreach ($teams as $team => $weight) {
        if (($roll -= $weight) <= 0) {
            $leads[] = $team;

            break;
        }
    }
}

echo implode(' ', $leads), PHP_EOL;
```

```text
south west north west north north north north west north
```

That's right on average, over a long run. Over these ten leads, north got six, south got one and west got three.

Here's the same split with the package:

```php
<?php

use Keaton\WeightedDistribution\WeightedDistribution;

$teams = WeightedDistribution::make('sales-leads')
    ->target('north', 5)
    ->target('south', 3)
    ->target('west', 2);

$leads = [];

for ($lead = 1; $lead <= 10; $lead++) {
    $leads[] = $teams->allocate()->target;
}

echo implode(' ', $leads), PHP_EOL;
```

```text
north south north west north south north west south north
```

North got five, south got three and west got two, and each team's leads are spread through the run. You ask `allocate()` where the next item goes, and it keeps count for you.

## What you can do with it

- Route leads to teams or buyers by weight, with a daily cap on each. When one is full, the others share its leads by their own weights. See [Caps](04-caps.md).
- Split traffic for an A/B test. The groups are the right size from the first visitor. See [Basic usage](03-basic-usage.md).
- Send exactly one order in every twenty to a manual review, with the reviews twenty orders apart.
- Spread API calls over two providers, with each provider's rate limit as its cap.
- Roll a change out to one request in ten, then turn the weights up. [Recipes](05-recipes.md) has the code for these three.
- Save the reason for every pick. `explain()` returns it as a sentence. See [The decision and the report](06-the-decision-and-the-report.md).
- Run it on every queue worker. With the cache store, they hand out one sequence between them. See [In a Laravel app](08-in-laravel.md).

## What it doesn't do

The package only decides where the next item should go. It doesn't deliver, validate or retry anything.

The scheduling rule is plain integer arithmetic. There is no randomness to tune and no seed to set.

**Next:** [Installation](02-installation.md).
