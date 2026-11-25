<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Support;

use Closure;
use Keaton\WeightedDistribution\Contracts\Store;

// A store that breaks the contract: it never runs the update callback.
final class BrokenStore implements Store
{
    public function get(string $key): ?array
    {
        return null;
    }

    public function update(string $key, Closure $callback): array
    {
        return [];
    }

    public function forget(string $key): void
    {
        //
    }
}
