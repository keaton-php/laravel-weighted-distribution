<!-- nav-group: Basic usage -->
# Recipes

A distribution answers one question, which is where the next item goes. Here are a few things you can build on that.

## One in every twenty

Say you want to check one order in every twenty by hand. A random 5% would sometimes pick two orders in a row, and then none for a long while. With weights of 19 and 1 you get exactly one in every twenty, and the reviews are evenly spaced.

```php
<?php

use Keaton\WeightedDistribution\WeightedDistribution;

$orders = WeightedDistribution::make('order-review')
    ->target('ship', 19)
    ->target('review', 1);

$reviewed = [];

for ($order = 1; $order <= 60; $order++) {
    if ($orders->allocate()->target === 'review') {
        $reviewed[] = $order;
    }
}

echo 'reviewed: ', implode(', ', $reviewed), PHP_EOL;
```

```text
reviewed: 11, 31, 51
```

## Rate limits as caps

Say you send text messages through two providers. The primary should take three of every four, and each provider has a limit per minute. Give each provider its limit as a cap. The example passes a fixed clock so that all twelve messages fall in the same minute. `hasCapacity()` returns `false` when both are full, so you know when to wait.

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\WeightedDistribution;

$sms = WeightedDistribution::make('sms', clock: fn () => strtotime('2026-03-02 09:00:00 UTC'))
    ->target('primary', 3, Cap::perMinute(6))
    ->target('backup', 1, Cap::perMinute(4));

$sent = [];
$held = 0;

for ($message = 1; $message <= 12; $message++) {
    if ($sms->hasCapacity()) {
        $sent[] = $sms->allocate()->target;
    } else {
        $held++;
    }
}

echo implode(' ', $sent), PHP_EOL;
echo "held back: {$held}", PHP_EOL;
```

```text
primary primary backup primary primary primary backup primary backup backup
held back: 2
```

Twelve messages came in during one minute. The primary took its six and the backup took its four, and the last two had to wait for the next minute. In a queued job you would release the job back onto the queue.

## Turning a rollout up

Say you're moving checkout to new code. You start by sending it one request in ten, and later you turn it up to half. The state is saved under the distribution's name, so you only change the weights.

```php
<?php

use Keaton\WeightedDistribution\Stores\ArrayStore;
use Keaton\WeightedDistribution\WeightedDistribution;

$store = new ArrayStore;

$count = function (WeightedDistribution $checkout): int {
    $new = 0;

    for ($request = 1; $request <= 20; $request++) {
        $new += $checkout->allocate()->target === 'new' ? 1 : 0;
    }

    return $new;
};

$careful = WeightedDistribution::make('checkout', $store)->target('current', 9)->target('new', 1);

echo 'at 9:1 the new code took ', $count($careful), ' of 20', PHP_EOL;

$half = WeightedDistribution::make('checkout', $store)->target('current', 1)->target('new', 1);

echo 'at 1:1 the new code took ', $count($half), ' of 20', PHP_EOL;
```

```text
at 9:1 the new code took 2 of 20
at 1:1 the new code took 10 of 20
```

A change of weights starts a new cycle, so the new ratio is exact from the next pick. The package doesn't try to make up for what the old ratio handed out.

## More ideas

- Weights don't have to be small. To share support tickets by the hours each person works, use the hours as the weights. `40` and `20` behave like `2` and `1`.
- A cap can be a budget. A buyer who pays for 120 leads a day gets `Cap::perDay(120)`, and the other buyers share the rest. [Caps](04-caps.md) shows what happens when a target is full.
- Save `explain()` with the item you handed out, and you can answer "why did this one go there?" months later. See [The decision and the report](06-the-decision-and-the-report.md).

**Next:** [The decision and the report](06-the-decision-and-the-report.md).
