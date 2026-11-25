<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Keaton\WeightedDistribution\Contracts\Store;
use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\Facades\WeightedDistribution as Distribution;
use Keaton\WeightedDistribution\Stores\ArrayStore;
use Keaton\WeightedDistribution\Stores\CacheStore;
use Keaton\WeightedDistribution\Tests\Support\RecordingStore;
use Keaton\WeightedDistribution\Tests\TestCase;
use Keaton\WeightedDistribution\WeightedDistribution;
use Keaton\WeightedDistribution\WeightedDistributionServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('weighted_distribution.targets', [
            'alpha' => ['weight' => 2],
            'beta' => ['weight' => 1, 'cap' => ['limit' => 5, 'per' => 'day']],
        ]);
    }

    #[Test]
    public function it_merges_the_default_configuration(): void
    {
        $this->assertSame('default', config('weighted_distribution.name'));
        $this->assertSame('array', config('weighted_distribution.store'));
        $this->assertNull(config('weighted_distribution.cache.ttl'));
    }

    #[Test]
    public function it_builds_the_default_distribution_from_config(): void
    {
        $distribution = app(WeightedDistribution::class);

        $this->assertSame('default', $distribution->name());
        $this->assertSame(['alpha', 'beta'], array_keys($distribution->report()->targets));
        $this->assertSame(5, $distribution->report()->usage('beta')->limit);
    }

    #[Test]
    public function it_resolves_one_shared_instance(): void
    {
        $this->assertSame(app(WeightedDistribution::class), app(WeightedDistribution::class));
        $this->assertSame(app(Store::class), app(Store::class));
    }

    #[Test]
    public function it_allocates_through_the_facade(): void
    {
        $sequence = [];

        for ($i = 0; $i < 6; $i++) {
            $sequence[] = Distribution::allocate()->target;
        }

        $this->assertSame(['alpha', 'beta', 'alpha', 'alpha', 'beta', 'alpha'], $sequence);
        $this->assertSame(6, Distribution::report()->allocated);
        $this->assertTrue(Distribution::hasCapacity());
    }

    #[Test]
    public function it_uses_the_configured_distribution_name(): void
    {
        config()->set('weighted_distribution.name', 'leads');

        $this->assertSame('leads', Distribution::name());
    }

    #[Test]
    public function it_uses_the_array_store_by_default(): void
    {
        $this->assertInstanceOf(ArrayStore::class, app(Store::class));
    }

    #[Test]
    public function it_uses_a_cache_store_when_configured(): void
    {
        config()->set('weighted_distribution.store', 'cache');
        config()->set('weighted_distribution.cache.prefix', 'wd-test');

        $this->assertInstanceOf(CacheStore::class, app(Store::class));

        Distribution::allocate();

        $this->assertTrue(Cache::has('wd-test:state:'.sha1('default')));
    }

    #[Test]
    public function it_resolves_a_custom_store_class_through_the_container(): void
    {
        config()->set('weighted_distribution.store', RecordingStore::class);

        Distribution::allocate();

        $store = app(Store::class);

        $this->assertInstanceOf(RecordingStore::class, $store);
        $this->assertSame(1, $store->updates);
    }

    #[Test]
    public function it_rejects_an_unknown_store(): void
    {
        config()->set('weighted_distribution.store', 'carrier-pigeon');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Unknown store [carrier-pigeon] in [weighted_distribution.store]. Use [array], [cache] or a class that implements the Store contract.');

        app(Store::class);
    }

    #[Test]
    public function it_rejects_a_target_definition_without_a_weight(): void
    {
        config()->set('weighted_distribution.targets', ['alpha' => 'five']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.targets.alpha] definition must be an array with an integer [weight].');

        app(WeightedDistribution::class);
    }

    #[Test]
    public function it_rejects_targets_that_are_not_an_array(): void
    {
        config()->set('weighted_distribution.targets', 'alpha');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.targets] configuration value must be an array.');

        app(WeightedDistribution::class);
    }

    #[Test]
    public function it_publishes_the_config_file_under_its_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(WeightedDistributionServiceProvider::class, 'weighted_distribution-config');

        $this->assertCount(1, $paths);
        $this->assertFileExists((string) array_key_first($paths));
        $this->assertStringEndsWith('config/weighted_distribution.php', (string) array_key_first($paths));
    }

    #[Test]
    public function it_rejects_a_distribution_name_that_is_not_a_string(): void
    {
        config()->set('weighted_distribution.name', 42);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.name] configuration value must be a string.');

        app(WeightedDistribution::class);
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function badCacheSettings(): array
    {
        return [
            'store' => ['store', 7, 'The [weighted_distribution.cache.store] configuration value must be a cache store name or null.'],
            'prefix' => ['prefix', false, 'The [weighted_distribution.cache.prefix] configuration value must be a string.'],
            'lock_seconds from env' => ['lock_seconds', '30', 'The [weighted_distribution.cache.lock_seconds] configuration value must be an integer.'],
            'lock_wait' => ['lock_wait', 1.5, 'The [weighted_distribution.cache.lock_wait] configuration value must be an integer.'],
            'ttl from env' => ['ttl', '3600', 'The [weighted_distribution.cache.ttl] configuration value must be an integer or null.'],
        ];
    }

    #[Test]
    #[DataProvider('badCacheSettings')]
    public function it_rejects_a_cache_setting_of_the_wrong_type(string $key, mixed $value, string $message): void
    {
        config()->set('weighted_distribution.store', 'cache');
        config()->set("weighted_distribution.cache.{$key}", $value);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        app(Store::class);
    }

    #[Test]
    public function it_rejects_a_cache_block_that_is_not_an_array(): void
    {
        config()->set('weighted_distribution.store', 'cache');
        config()->set('weighted_distribution.cache', 'redis');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.cache] configuration value must be an array.');

        app(Store::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function typos(): array
    {
        return [
            'top level' => ['weighted_distribution.stores', 'Unknown configuration key [weighted_distribution.stores]. The known keys are [name], [store], [cache], [targets].'],
            'cache block' => ['weighted_distribution.cache.lock_second', 'Unknown configuration key [weighted_distribution.cache.lock_second]. The known keys are [cache.store], [cache.prefix], [cache.lock_seconds], [cache.lock_wait], [cache.ttl].'],
        ];
    }

    #[Test]
    #[DataProvider('typos')]
    public function it_rejects_a_configuration_key_it_does_not_know(string $key, string $message): void
    {
        config()->set($key, 30);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        app(Store::class);
    }

    #[Test]
    public function it_checks_the_cache_block_even_when_another_store_is_in_use(): void
    {
        config()->set('weighted_distribution.store', 'array');
        config()->set('weighted_distribution.cache', 'redis');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.cache] configuration value must be an array.');

        app(Store::class);
    }

    #[Test]
    public function it_checks_cache_settings_even_when_another_store_is_in_use(): void
    {
        config()->set('weighted_distribution.store', 'array');
        config()->set('weighted_distribution.cache.ttl', '3600');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.cache.ttl] configuration value must be an integer or null.');

        app(WeightedDistribution::class);
    }
}
