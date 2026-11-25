<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Feature;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\Exceptions\NoTargetAvailable;
use Keaton\WeightedDistribution\Exceptions\StoreFailure;
use Keaton\WeightedDistribution\Stores\CacheStore;
use Keaton\WeightedDistribution\Tests\Concerns\MeasuresFairness;
use Keaton\WeightedDistribution\Tests\Support\UnreliableCacheStore;
use Keaton\WeightedDistribution\Tests\TestCase;
use Keaton\WeightedDistribution\WeightedDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CacheStoreTest extends TestCase
{
    use MeasuresFairness;

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function distribution(string $name = 'leads', ?CacheStore $store = null): WeightedDistribution
    {
        return WeightedDistribution::make($name, $store ?? new CacheStore(Cache::store('array')))
            ->target('a', 2)
            ->target('b', 1);
    }

    #[Test]
    public function it_shares_the_sequence_between_instances_through_the_cache(): void
    {
        $first = $this->distribution();
        $second = $this->distribution();

        $sequence = [];

        for ($i = 0; $i < 6; $i++) {
            $sequence[] = ($i % 2 === 0 ? $first : $second)->allocate()->target;
        }

        $this->assertSame(['a', 'b', 'a', 'a', 'b', 'a'], $sequence);
    }

    #[Test]
    public function it_saves_the_state_under_a_prefixed_key(): void
    {
        $this->distribution()->allocate();

        $this->assertTrue(Cache::store('array')->has('weighted-distribution:state:'.sha1('leads')));
    }

    #[Test]
    public function it_honors_a_custom_prefix_and_ttl(): void
    {
        $this->distribution('leads', new CacheStore(Cache::store('array'), prefix: 'wd', ttl: 60))->allocate();

        $this->assertTrue(Cache::store('array')->has('wd:state:'.sha1('leads')));
        $this->assertFalse(Cache::store('array')->has('weighted-distribution:state:'.sha1('leads')));
    }

    #[Test]
    public function it_keeps_distributions_with_different_names_apart(): void
    {
        $this->assertSame('a', $this->distribution('x')->allocate()->target);
        $this->assertSame('b', $this->distribution('x')->allocate()->target);
        $this->assertSame('a', $this->distribution('y')->allocate()->target);
    }

    #[Test]
    public function it_forgets_the_state_on_reset(): void
    {
        $distribution = $this->distribution();
        $distribution->allocate();

        $distribution->reset();

        $this->assertFalse(Cache::store('array')->has('weighted-distribution:state:'.sha1('leads')));
        $this->assertSame(0, $distribution->report()->allocated);
    }

    #[Test]
    public function it_reports_from_the_cached_state(): void
    {
        $distribution = $this->distribution();
        $distribution->allocate();
        $distribution->allocate();

        $this->assertSame(2, $this->distribution()->report()->allocated);
    }

    #[Test]
    public function it_stays_fair_across_many_allocations(): void
    {
        $sequence = $this->pick($this->distribution(), 90);

        $this->assertFair($sequence, ['a' => 2, 'b' => 1]);
    }

    #[Test]
    public function it_fails_when_the_lock_cannot_be_obtained(): void
    {
        $this->assertTrue(Cache::lock('weighted-distribution:lock:'.sha1('leads'), 30)->get());

        $distribution = $this->distribution('leads', new CacheStore(Cache::store('array'), lockWait: 0));

        $this->expectException(StoreFailure::class);
        $this->expectExceptionMessage('Could not obtain the lock for [leads] in time.');

        $distribution->allocate();
    }

    #[Test]
    public function it_releases_the_lock_when_the_scheduler_throws(): void
    {
        $empty = WeightedDistribution::make('empty', new CacheStore(Cache::store('array')));

        try {
            $empty->allocate();
            $this->fail('Expected the empty distribution to throw.');
        } catch (NoTargetAvailable) {
            // expected
        }

        $lock = Cache::lock('weighted-distribution:lock:'.sha1('empty'), 5);

        $this->assertTrue($lock->get());
        $lock->release();
    }

    /**
     * @return array<string, array{int, int, ?int, string}>
     */
    public static function badStoreSettings(): array
    {
        return [
            'a lock that never expires' => [0, 5, null, "The cache store's [lock_seconds] must be at least 1, [0] given."],
            'a negative wait' => [10, -1, null, "The cache store's [lock_wait] must be 0 or more, [-1] given."],
            'a ttl that deletes the state' => [10, 5, 0, "The cache store's [ttl] must be at least 1, or null, [0] given."],
        ];
    }

    #[Test]
    #[DataProvider('badStoreSettings')]
    public function it_rejects_settings_that_would_fail_silently(int $lockSeconds, int $lockWait, ?int $ttl, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        new CacheStore(Cache::store('array'), lockSeconds: $lockSeconds, lockWait: $lockWait, ttl: $ttl);
    }

    #[Test]
    public function a_distribution_named_like_a_lock_key_never_lands_on_another_distributions_lock(): void
    {
        // The file cache keeps locks and values in one key space, as redis and memcached do.
        $cache = Cache::store('file');
        $cache->flush();

        try {
            $this->distribution('lock:leads', new CacheStore($cache, lockWait: 0))->allocate();

            $this->assertTrue($cache->has('weighted-distribution:state:'.sha1('lock:leads')));
            $this->assertSame('a', $this->distribution('leads', new CacheStore($cache, lockWait: 0))->allocate()->target);
        } finally {
            $cache->flush();
        }
    }

    #[Test]
    public function reset_waits_for_the_same_lock_as_an_allocation(): void
    {
        $distribution = $this->distribution('leads', new CacheStore(Cache::store('array'), lockWait: 0));
        $distribution->allocate();

        $this->assertTrue(Cache::lock('weighted-distribution:lock:'.sha1('leads'), 30)->get());

        try {
            $distribution->reset();
            $this->fail('Expected reset() to wait for the lock.');
        } catch (StoreFailure $e) {
            $this->assertSame('Could not obtain the lock for [leads] in time.', $e->getMessage());
        }

        $this->assertTrue(Cache::store('array')->has('weighted-distribution:state:'.sha1('leads')));
    }

    #[Test]
    public function an_allocation_the_cache_did_not_save_is_an_error(): void
    {
        $cache = new UnreliableCacheStore;
        $distribution = $this->distribution('leads', new CacheStore(new Repository($cache)));
        $distribution->allocate();

        $cache->saves = false;

        try {
            $distribution->allocate();
            $this->fail('Expected an unsaved allocation to throw.');
        } catch (StoreFailure $e) {
            $this->assertSame('The cache store did not save the state for [leads].', $e->getMessage());
        }

        $this->assertSame(1, $distribution->report()->allocated);
    }

    #[Test]
    public function a_reset_the_cache_did_not_carry_out_is_an_error(): void
    {
        $cache = new UnreliableCacheStore;
        $distribution = $this->distribution('leads', new CacheStore(new Repository($cache)));
        $distribution->allocate();

        $cache->deletes = false;

        $this->expectException(StoreFailure::class);
        $this->expectExceptionMessage('The cache store did not delete the state for [leads].');

        $distribution->reset();
    }

    #[Test]
    public function resetting_a_distribution_that_was_never_saved_is_fine(): void
    {
        $distribution = $this->distribution('never-used');

        $this->assertSame(0, $distribution->reset()->report()->allocated);
    }

    #[Test]
    public function any_name_becomes_a_short_backend_safe_key(): void
    {
        $name = str_repeat("a long name with spaces\tand control bytes\x01 ", 20);

        $this->assertSame('a', $this->distribution($name)->allocate()->target);
        $this->assertSame('b', $this->distribution($name)->allocate()->target);

        $key = 'weighted-distribution:state:'.sha1($name);
        $this->assertTrue(Cache::store('array')->has($key));
        $this->assertMatchesRegularExpression('/^[a-z-]+:state:[0-9a-f]{40}$/', $key);
    }

    #[Test]
    public function something_else_at_the_state_key_is_an_error_not_a_fresh_start(): void
    {
        $distribution = $this->distribution('leads');
        $distribution->allocate();

        Cache::store('array')->forever('weighted-distribution:state:'.sha1('leads'), 'overwritten by mistake');

        $this->expectException(StoreFailure::class);
        $this->expectExceptionMessage('The cache holds something other than saved state for [leads]; call reset() to start over.');

        $distribution->allocate();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePrefixes(): array
    {
        return [
            'empty' => [''],
            'a space' => ['weighted distribution'],
            'a control byte' => ["wd\x01"],
            'too long' => [str_repeat('p', 65)],
            'a trailing newline' => ["wd\n"],
            'a full-length prefix with a trailing newline' => [str_repeat('p', 64)."\n"],
        ];
    }

    #[Test]
    #[DataProvider('unsafePrefixes')]
    public function it_refuses_a_prefix_some_cache_backends_would_reject(string $prefix): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage("The cache store's [prefix] must be 1 to 64 letters, digits, dots, colons, dashes or underscores");

        new CacheStore(Cache::store('array'), $prefix);
    }

    #[Test]
    public function the_longest_prefix_it_accepts_still_makes_a_short_key(): void
    {
        $store = new CacheStore(Cache::store('array'), str_repeat('p', 64));
        $this->distribution('leads', $store)->allocate();

        $key = str_repeat('p', 64).':state:'.sha1('leads');
        $this->assertTrue(Cache::store('array')->has($key));
        $this->assertLessThan(250, strlen($key));
    }
}
