# Laravel Weighted Distribution

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keaton-php/laravel-weighted-distribution.svg?style=flat-square)](https://packagist.org/packages/keaton-php/laravel-weighted-distribution)
[![Tests](https://img.shields.io/github/actions/workflow/status/keaton-php/laravel-weighted-distribution/run-tests.yml?branch=1.x&label=tests&style=flat-square)](https://github.com/keaton-php/laravel-weighted-distribution/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/keaton-php/laravel-weighted-distribution.svg?style=flat-square)](https://packagist.org/packages/keaton-php/laravel-weighted-distribution)

Laravel Weighted Distribution hands things out in a fixed ratio, one at a time. It doesn't roll dice, so the ratio is exact after every pick and not only on average.

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

foreach (array_keys($teams) as $team) {
    echo $team, ': ', array_count_values($leads)[$team] ?? 0, PHP_EOL;
}
```

```text
south west north west north north north north west north
north: 6
south: 1
west: 3
```

That's right on average, over a long run. Over these ten leads, south got one lead when it should have had three, and north got four in a row.

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

foreach (['north', 'south', 'west'] as $team) {
    echo $team, ': ', array_count_values($leads)[$team] ?? 0, PHP_EOL;
}
```

```text
north south north west north south north west south north
north: 5
south: 3
west: 2
```

Exactly 5, 3 and 2, and each team's leads are spread through the run. You ask `allocate()` where the next item goes, and it keeps count for you.

It stays exact however long it runs. After every pick, each target is less than one item away from its exact share. Over 1,000 leads the random pick above is off by up to 21.5 leads, and the package is never off by more than 0.6.

## What you can do with it

- Route leads to teams or buyers by weight, with a daily cap on each. When one is full, the others share its leads by their own weights.
- Split traffic for an A/B test. The groups are the right size from the first visitor.
- Send exactly one order in every twenty to a manual review, with the reviews twenty orders apart.
- Spread API calls over two providers, with each provider's rate limit as its cap.
- Roll a change out to one request in ten, then turn the weights up.
- Save the reason for every pick. `explain()` returns it as a sentence.
- Run it on every queue worker. With the cache store, they hand out one sequence between them.

The [Recipes](docs/05-recipes.md) page has the code for these.

## Caps and reasons

A target can have a cap, like `Cap::perDay(3)`. Here west can take three leads a day, and twenty leads come in:

```php
<?php

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\WeightedDistribution;

// a fixed clock, so this prints the same date on every run
$teams = WeightedDistribution::make('sales-leads', clock: fn () => strtotime('2026-03-02 09:00:00 UTC'))
    ->target('north', 5)
    ->target('south', 3)
    ->target('west', 2, Cap::perDay(3));

for ($lead = 1; $lead <= 20; $lead++) {
    $decision = $teams->allocate();
}

foreach ($teams->report()->targets as $team) {
    echo $team->target, ': ', $team->allocated, PHP_EOL;
}

echo PHP_EOL, $decision->explain(), PHP_EOL;
```

```text
north: 11
south: 6
west: 3

Allocation #20: 'north' was chosen. It was owed 0.75 of an allocation (slot 6 of 8 in this cycle) and was the only target due one. Not due one yet: south. At their cap: west (3/3 until 2026-03-03 00:00:00 UTC).
```

West stopped at three, and north and south shared the rest in their own 5:3 ratio. The last line is the reason for the twentieth pick. Every pick has one, so you can save it with the lead and answer "why did this one go to north?" later.

## Installation

You can install the package via composer:

```bash
composer require keaton-php/laravel-weighted-distribution
```

The package requires PHP 8.2 or higher and Laravel 11, 12 or 13.

You can publish the config file with:

```bash
php artisan vendor:publish --tag="weighted_distribution-config"
```

## Documentation

You'll find the documentation on [kevineaton.dev/docs/laravel-weighted-distribution](https://kevineaton.dev/docs/laravel-weighted-distribution/). [Basic usage](https://kevineaton.dev/docs/laravel-weighted-distribution/basic-usage/) is a good place to start. The same pages are in the [docs](docs) folder of this repository:

- [Introduction](docs/index.md)
- [Installation](docs/02-installation.md)
- [Basic usage](docs/03-basic-usage.md)
- [Caps](docs/04-caps.md)
- [Recipes](docs/05-recipes.md)
- [The decision and the report](docs/06-the-decision-and-the-report.md)
- [Stores](docs/07-stores.md)
- [In a Laravel app](docs/08-in-laravel.md)
- [Reference](docs/09-reference.md)

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Kevin Eaton](https://github.com/keaton-php)

The scheduling rule comes from R. Tijdeman, "The chairman assignment problem", *Discrete Mathematics* 32 (1980).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
