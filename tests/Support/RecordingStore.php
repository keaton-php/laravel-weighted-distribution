<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Support;

use Closure;
use Keaton\WeightedDistribution\Stores\ArrayStore;

class RecordingStore extends ArrayStore
{
    public int $reads = 0;

    public int $updates = 0;

    public int $forgets = 0;

    public function get(string $key): ?array
    {
        $this->reads++;

        return parent::get($key);
    }

    public function update(string $key, Closure $callback): array
    {
        $this->updates++;

        return parent::update($key, $callback);
    }

    public function forget(string $key): void
    {
        $this->forgets++;

        parent::forget($key);
    }
}
