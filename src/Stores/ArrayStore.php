<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Stores;

use Closure;
use Keaton\WeightedDistribution\Contracts\Store;

// The memory of one PHP process: each process (an FPM request, a queue or Octane worker) has its own. Use CacheStore across workers.
class ArrayStore implements Store
{
    /** @var array<string, array<string, mixed>> */
    protected array $states = [];

    public function get(string $key): ?array
    {
        return $this->states[$key] ?? null;
    }

    public function update(string $key, Closure $callback): array
    {
        return $this->states[$key] = $callback($this->states[$key] ?? null);
    }

    public function forget(string $key): void
    {
        unset($this->states[$key]);
    }
}
