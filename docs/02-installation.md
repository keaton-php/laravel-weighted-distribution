<!-- nav-group: Start here -->
# Installation

## Requirements

- PHP 8.2 or higher
- Laravel 11.33.2 or higher, 12 or 13 (Laravel 13 needs PHP 8.3)

Only `CacheStore`, the facade and the service provider depend on Laravel. Everything else works in plain PHP.

## Installing the package

You can install the package via composer:

```bash
composer require keaton-php/laravel-weighted-distribution
```

The service provider and the facade are discovered automatically.

## Publishing the config file

If you want to use the facade, publish the config file:

```bash
php artisan vendor:publish --tag="weighted_distribution-config"
```

You can then describe your distribution in `config/weighted_distribution.php`. Settings you leave out keep their defaults. [In a Laravel app](08-in-laravel.md) covers the config file and the facade. Without Laravel you call `make()` and add the targets with `target()` or `targets()`, like the examples in these docs do.

The package is tested on PHP 8.2 to 8.4 against Laravel 11, 12 and 13.

**Next:** [Basic usage](03-basic-usage.md).
