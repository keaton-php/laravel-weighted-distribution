<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Keaton\WeightedDistribution\Contracts\Store;
use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\Stores\ArrayStore;
use Keaton\WeightedDistribution\Stores\CacheStore;

class WeightedDistributionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/weighted_distribution.php', 'weighted_distribution');

        // Singletons: the default array store keeps its state on the instance.
        $this->app->singleton(Store::class, function (Application $app): Store {
            return $this->resolveStore($app, $this->settings($app));
        });

        $this->app->singleton(WeightedDistribution::class, function (Application $app): WeightedDistribution {
            $settings = $this->settings($app);

            return WeightedDistribution::make($settings['name'], $app->make(Store::class))
                ->targets($settings['targets']);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/weighted_distribution.php' => config_path('weighted_distribution.php'),
            ], 'weighted_distribution-config');
        }
    }

    /**
     * The whole config, checked whichever store is in use: an unknown key or a
     * value of the wrong type throws instead of quietly falling back.
     *
     * @return array{name: string, store: mixed, cache: array{store: ?string, prefix: string, lock_seconds: int, lock_wait: int, ttl: ?int}, targets: array<array-key, mixed>}
     */
    protected function settings(Application $app): array
    {
        $settings = $app->make(Config::class)->get('weighted_distribution', []);

        if (! is_array($settings)) {
            throw InvalidConfiguration::notAnArray('');
        }

        $this->rejectUnknownKeys($settings, ['name', 'store', 'cache', 'targets'], '');

        $name = $settings['name'] ?? 'default';
        $targets = $settings['targets'] ?? [];
        $cache = $settings['cache'] ?? [];

        if (! is_string($name)) {
            throw InvalidConfiguration::invalidValue('name', 'a string');
        }

        if (! is_array($targets)) {
            throw InvalidConfiguration::notAnArray('targets');
        }

        if (! is_array($cache)) {
            throw InvalidConfiguration::notAnArray('cache');
        }

        $this->rejectUnknownKeys($cache, ['store', 'prefix', 'lock_seconds', 'lock_wait', 'ttl'], 'cache.');

        $store = $cache['store'] ?? null;
        $prefix = $cache['prefix'] ?? 'weighted-distribution';
        $lockSeconds = $cache['lock_seconds'] ?? 10;
        $lockWait = $cache['lock_wait'] ?? 5;
        $ttl = $cache['ttl'] ?? null;

        if ($store !== null && ! is_string($store)) {
            throw InvalidConfiguration::invalidValue('cache.store', 'a cache store name or null');
        }

        if (! is_string($prefix)) {
            throw InvalidConfiguration::invalidValue('cache.prefix', 'a string');
        }

        if (! is_int($lockSeconds)) {
            throw InvalidConfiguration::invalidValue('cache.lock_seconds', 'an integer');
        }

        if (! is_int($lockWait)) {
            throw InvalidConfiguration::invalidValue('cache.lock_wait', 'an integer');
        }

        if ($ttl !== null && ! is_int($ttl)) {
            throw InvalidConfiguration::invalidValue('cache.ttl', 'an integer or null');
        }

        return [
            'name' => $name,
            'store' => $settings['store'] ?? 'array',
            'cache' => ['store' => $store, 'prefix' => $prefix, 'lock_seconds' => $lockSeconds, 'lock_wait' => $lockWait, 'ttl' => $ttl],
            'targets' => $targets,
        ];
    }

    /**
     * A typo'd setting would otherwise fall back to its default without a word.
     *
     * @param  array<array-key, mixed>  $settings
     * @param  list<string>  $known
     */
    protected function rejectUnknownKeys(array $settings, array $known, string $path): void
    {
        foreach (array_keys($settings) as $key) {
            if (! in_array($key, $known, true)) {
                throw InvalidConfiguration::unknownKey($path.$key, $path.implode("], [{$path}", $known));
            }
        }
    }

    /**
     * @param  array{name: string, store: mixed, cache: array{store: ?string, prefix: string, lock_seconds: int, lock_wait: int, ttl: ?int}, targets: array<array-key, mixed>}  $settings
     */
    protected function resolveStore(Application $app, array $settings): Store
    {
        $driver = $settings['store'];

        if ($driver === 'array') {
            return new ArrayStore;
        }

        if ($driver === 'cache') {
            $cache = $settings['cache'];

            return new CacheStore(
                $app->make(CacheFactory::class)->store($cache['store']),
                $cache['prefix'],
                $cache['lock_seconds'],
                $cache['lock_wait'],
                $cache['ttl'],
            );
        }

        if (is_string($driver) && is_subclass_of($driver, Store::class)) {
            return $app->make($driver);
        }

        throw InvalidConfiguration::unknownStore(is_string($driver) ? $driver : get_debug_type($driver));
    }
}
