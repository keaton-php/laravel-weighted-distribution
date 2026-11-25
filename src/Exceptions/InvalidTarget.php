<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Exceptions;

use InvalidArgumentException;

class InvalidTarget extends InvalidArgumentException
{
    public static function emptyName(): self
    {
        return new self('A target name cannot be empty.');
    }

    public static function invalidUtf8(): self
    {
        return new self('A target name must be valid UTF-8.');
    }

    public static function duplicate(string $name): self
    {
        return new self("The target [{$name}] is already registered.");
    }

    public static function weightMustBePositive(string $name, int $weight): self
    {
        return new self("The weight of target [{$name}] must be a positive integer, [{$weight}] given.");
    }

    public static function weightsTooLarge(): self
    {
        return new self('The weights are too large to schedule with exact integer arithmetic, even after dividing out their common factor; use smaller weights.');
    }

    public static function unknown(string $name): self
    {
        return new self("The target [{$name}] is not registered.");
    }
}
