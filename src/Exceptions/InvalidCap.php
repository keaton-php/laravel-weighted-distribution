<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Exceptions;

use InvalidArgumentException;

class InvalidCap extends InvalidArgumentException
{
    public static function limitMustBePositive(int $limit): self
    {
        return new self("A cap limit must be at least 1, [{$limit}] given.");
    }

    public static function windowMustBePositive(int $seconds): self
    {
        return new self("A cap window must be at least 1 second, [{$seconds}] given.");
    }

    public static function unknownUnit(string $unit): self
    {
        return new self("Unknown cap window [{$unit}]. Use second, minute, hour, day or week, or a number of seconds.");
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function unknownKeys(array $keys): self
    {
        $list = implode('], [', $keys);

        return new self("A cap definition has unknown keys [{$list}]; a cap takes [limit] and [per].");
    }

    public static function malformed(): self
    {
        return new self('A cap definition must be an array with an integer [limit] and a [per] window.');
    }
}
