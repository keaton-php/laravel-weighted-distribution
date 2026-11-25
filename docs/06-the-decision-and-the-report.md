<!-- nav-group: Advanced -->
# The decision and the report

`allocate()` returns a `Decision` for one pick. `report()` covers the whole life of the distribution.

## Explaining a pick

`$decision->target` is the name of the target that was picked. `explain()` returns the reason as text you can log.

```php
<?php

use Keaton\WeightedDistribution\WeightedDistribution;

$pages = WeightedDistribution::make('homepage')
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2);

$decision = $pages->allocate();

echo $decision->target, PHP_EOL;
echo $decision->explain(), PHP_EOL;
```

```text
new-home
Allocation #1: 'new-home' was chosen. It was owed 0.50 of an allocation (slot 1 of 10 in this cycle) and had the earliest deadline of the 2 targets due one. Not due one yet: experiment.
```

"Owed" is how far a target is behind its exact share, counting this pick. A target only becomes due a pick once it is owed enough, which stops any target from running ahead of its share.

## The whole decision

`peek()` returns the decision `allocate()` would make now, without recording it. A decision also has its position in the cycle, and a `Standing` for every target as it stood just before the pick. The length of a cycle is the sum of the weights after dividing out their common factor, so `400 : 200` behaves like `2 : 1`.

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\WeightedDistribution;

$pages = WeightedDistribution::make('homepage', clock: fn () => strtotime('2026-03-02 09:00:00 UTC'))
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2, Cap::perDay(100));

$next = $pages->peek();          // what allocate() would do, recording nothing
$decision = $pages->allocate();

echo $next->target === $decision->target ? 'peek matched allocate' : 'peek differed', PHP_EOL;
echo "picked {$decision->target}, allocation {$decision->sequence}, slot {$decision->position} of {$decision->cycle}", PHP_EOL;

foreach ($decision->standings as $standing) {
    printf(
        "%-10s  %-9s  share %.1f  owed %.2f  %s\n",
        $standing->target,
        $standing->status->value,
        $standing->share,
        $standing->owed,
        $standing->limit === null ? 'no cap' : "{$standing->used} of {$standing->limit} used today",
    );
}
```

```text
peek matched allocate
picked new-home, allocation 1, slot 1 of 10
new-home    chosen     share 0.5  owed 0.50  no cap
old-home    candidate  share 0.3  owed 0.30  no cap
experiment  waiting    share 0.2  owed 0.20  0 of 100 used today
```

A target's status is `chosen`, `candidate` (due a pick, but another target won), `waiting` (not owed enough yet) or `saturated` (at its cap). After a full cycle every target has had exactly its share.

## The report

`report()` compares the share each target is configured to get with what it has actually had.

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\WeightedDistribution;

$pages = WeightedDistribution::make('homepage', clock: fn () => strtotime('2026-03-02 18:00:00 UTC'))
    ->target('new-home', 5)
    ->target('old-home', 3)
    ->target('experiment', 2, Cap::perDay(150));

for ($visitor = 1; $visitor <= 1000; $visitor++) {
    $pages->allocate();
}

$report = $pages->report();

echo "allocated {$report->allocated}, experiment saturated: ", $report->isSaturated('experiment') ? 'yes' : 'no', PHP_EOL;

foreach ($report->targets as $usage) {
    printf(
        "%-10s  share %3.0f%%  got %4d (%3.0f%%)%s\n",
        $usage->target,
        $usage->share * 100,
        $usage->allocated,
        $usage->actual * 100,
        $usage->limit === null ? '' : "  {$usage->remaining()} left until ".gmdate('H:i', $usage->resetsAt).' UTC',
    );
}
```

```text
allocated 1000, experiment saturated: yes
new-home    share  50%  got  532 ( 53%)
old-home    share  30%  got  318 ( 32%)
experiment  share  20%  got  150 ( 15%)  0 left until 00:00 UTC
```

The experiment hit its cap of 150, so the other two targets ended up above their configured share. `saturated()` returns the targets that are at their cap, and `available()` returns the ones that are not.

**Next:** [Stores](07-stores.md).
