<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Support;

use Illuminate\Cache\ArrayStore;

// An array cache that can be told to refuse writes or deletes, as a full or read-only backend would.
class UnreliableCacheStore extends ArrayStore
{
    public bool $saves = true;

    public bool $deletes = true;

    public function put($key, $value, $seconds): bool
    {
        return $this->saves && parent::put($key, $value, $seconds);
    }

    public function forever($key, $value): bool
    {
        return $this->saves && parent::forever($key, $value);
    }

    public function forget($key): bool
    {
        return $this->deletes ? parent::forget($key) : false;
    }
}
