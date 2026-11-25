<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Exceptions;

use InvalidArgumentException;

class InvalidConfiguration extends InvalidArgumentException
{
    public static function emptyName(): self
    {
        return new self('A distribution name cannot be empty.');
    }

    public static function notAnArray(string $key): self
    {
        $path = $key === '' ? 'weighted_distribution' : "weighted_distribution.{$key}";

        return new self("The [{$path}] configuration value must be an array.");
    }

    public static function invalidTarget(string $name): self
    {
        return new self("The [weighted_distribution.targets.{$name}] definition must be an array with an integer [weight].");
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function unknownTargetKeys(string $name, array $keys): self
    {
        $list = implode('], [', $keys);

        return new self("The [weighted_distribution.targets.{$name}] definition has unknown keys [{$list}]; a target takes [weight] and an optional [cap].");
    }

    public static function unknownKey(string $key, string $known): self
    {
        return new self("Unknown configuration key [weighted_distribution.{$key}]. The known keys are [{$known}].");
    }

    public static function invalidValue(string $key, string $expected): self
    {
        return new self("The [weighted_distribution.{$key}] configuration value must be {$expected}.");
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self("The cache store's [prefix] must be 1 to 64 letters, digits, dots, colons, dashes or underscores, [{$prefix}] given.");
    }

    public static function storeSetting(string $setting, int $value, string $expected): self
    {
        return new self("The cache store's [{$setting}] must be {$expected}, [{$value}] given.");
    }

    public static function unknownStore(string $driver): self
    {
        return new self("Unknown store [{$driver}] in [weighted_distribution.store]. Use [array], [cache] or a class that implements the Store contract.");
    }
}
