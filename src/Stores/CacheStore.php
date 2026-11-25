<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Stores;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Keaton\WeightedDistribution\Contracts\Store;
use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\Exceptions\StoreFailure;

// Shares state between workers through a Laravel cache store that supports atomic locks and that every worker reaches.
class CacheStore implements Store
{
    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'weighted-distribution',
        protected int $lockSeconds = 10,
        protected int $lockWait = 5,
        protected ?int $ttl = null,
    ) {
        // Every cache backend accepts these keys: a short, plain prefix plus a 40-character hash.
        if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $prefix) !== 1) {
            throw InvalidConfiguration::invalidPrefix($prefix);
        }

        // A lock that never expires, or a TTL that deletes the state as it is written, would fail silently.
        if ($lockSeconds < 1) {
            throw InvalidConfiguration::storeSetting('lock_seconds', $lockSeconds, 'at least 1');
        }

        if ($lockWait < 0) {
            throw InvalidConfiguration::storeSetting('lock_wait', $lockWait, '0 or more');
        }

        if ($ttl !== null && $ttl < 1) {
            throw InvalidConfiguration::storeSetting('ttl', $ttl, 'at least 1, or null');
        }
    }

    public function get(string $key): ?array
    {
        $state = $this->cache->get($this->stateKey($key));

        // Missing (which Laravel also reports for a stored null) is a fresh start; any other non-array is corruption.
        if ($state !== null && ! is_array($state)) {
            throw StoreFailure::corruptState($key);
        }

        return $state;
    }

    public function update(string $key, Closure $callback): array
    {
        $state = [];

        $this->locked($key, function () use ($key, $callback, &$state): void {
            $state = $callback($this->get($key));

            $this->put($key, $state);
        });

        return $state;
    }

    // Under the same lock as update(), so a reset can never be overwritten by an allocation already in flight.
    public function forget(string $key): void
    {
        $this->locked($key, function () use ($key): void {
            // Some stores report a missing key as a failed delete, so check the result instead.
            $this->cache->forget($this->stateKey($key));

            if ($this->cache->has($this->stateKey($key))) {
                throw StoreFailure::forgetFailed($key);
            }
        });
    }

    protected function locked(string $key, Closure $callback): void
    {
        try {
            $this->locks()
                ->lock($this->lockKey($key), $this->lockSeconds)
                ->block($this->lockWait, $callback);
        } catch (LockTimeoutException) {
            throw StoreFailure::lockTimeout($key);
        }
    }

    protected function locks(): LockProvider
    {
        $store = $this->cache instanceof CacheRepository ? $this->cache->getStore() : null;

        if (! $store instanceof LockProvider) {
            throw StoreFailure::locksNotSupported();
        }

        return $store;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function put(string $key, array $state): void
    {
        $saved = $this->ttl === null
            ? $this->cache->forever($this->stateKey($key), $state)
            : $this->cache->put($this->stateKey($key), $state, $this->ttl);

        // An unsaved pick would be handed out again, and its cap spent twice.
        if (! $saved) {
            throw StoreFailure::writeFailed($key);
        }
    }

    // State and lock keys live under different segments, so no distribution name can reach
    // another's lock, and the name is hashed so any name is a short, backend-safe key.
    protected function stateKey(string $key): string
    {
        return "{$this->prefix}:state:".sha1($key);
    }

    protected function lockKey(string $key): string
    {
        return "{$this->prefix}:lock:".sha1($key);
    }
}
