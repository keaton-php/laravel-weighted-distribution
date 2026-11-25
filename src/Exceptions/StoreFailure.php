<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Exceptions;

use RuntimeException;

class StoreFailure extends RuntimeException
{
    public static function lockTimeout(string $key): self
    {
        return new self("Could not obtain the lock for [{$key}] in time.");
    }

    public static function locksNotSupported(): self
    {
        return new self('The cache store does not support atomic locks; use one that does (array, redis, database, file or memcached).');
    }

    public static function stateNotUpdated(string $key): self
    {
        return new self("The store did not run the update callback for [{$key}].");
    }

    public static function invalidState(string $problem): self
    {
        return new self("The stored state is invalid: {$problem}; call reset() to start over.");
    }

    public static function corruptState(string $key): self
    {
        return new self("The cache holds something other than saved state for [{$key}]; call reset() to start over.");
    }

    public static function writeFailed(string $key): self
    {
        return new self("The cache store did not save the state for [{$key}].");
    }

    public static function forgetFailed(string $key): self
    {
        return new self("The cache store did not delete the state for [{$key}].");
    }

    public static function inconsistentState(): self
    {
        return new self('The stored state does not match the registered targets; call reset() to start over.');
    }
}
