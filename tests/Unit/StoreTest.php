<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Unit;

use Keaton\WeightedDistribution\Exceptions\StoreFailure;
use Keaton\WeightedDistribution\Stores\ArrayStore;
use Keaton\WeightedDistribution\Tests\Concerns\MeasuresFairness;
use Keaton\WeightedDistribution\Tests\Support\BrokenStore;
use Keaton\WeightedDistribution\Tests\Support\RecordingStore;
use Keaton\WeightedDistribution\WeightedDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    use MeasuresFairness;

    #[Test]
    public function the_array_store_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull((new ArrayStore)->get('missing'));
    }

    #[Test]
    public function the_array_store_hands_the_previous_state_to_the_callback_and_keeps_the_result(): void
    {
        $store = new ArrayStore;
        $seen = [];

        $store->update('k', function (?array $state) use (&$seen): array {
            $seen[] = $state;

            return ['n' => 1];
        });

        $returned = $store->update('k', function (?array $state) use (&$seen): array {
            $seen[] = $state;

            return ['n' => 2];
        });

        $this->assertSame([null, ['n' => 1]], $seen);
        $this->assertSame(['n' => 2], $returned);
        $this->assertSame(['n' => 2], $store->get('k'));
    }

    #[Test]
    public function the_array_store_forgets_a_key(): void
    {
        $store = new ArrayStore;
        $store->update('k', fn (): array => ['n' => 1]);

        $store->forget('k');

        $this->assertNull($store->get('k'));
    }

    #[Test]
    public function any_store_implementing_the_contract_can_be_swapped_in(): void
    {
        $store = new RecordingStore;
        $distribution = WeightedDistribution::make('swap', $store)->target('a', 2)->target('b', 1);

        $this->assertSame(['a', 'b', 'a'], $this->pick($distribution, 3));
        $this->assertSame(3, $store->updates);

        $distribution->report();
        $distribution->reset();

        $this->assertSame(1, $store->reads);
        $this->assertSame(1, $store->forgets);
        $this->assertNull($store->get('swap'));
    }

    #[Test]
    public function it_saves_only_plain_values_so_any_backend_can_hold_the_state(): void
    {
        $store = new ArrayStore;
        WeightedDistribution::make('plain', $store)->target('a', 1)->target('b', 1)->allocate();

        $state = $store->get('plain');

        $this->assertIsArray($state);
        $this->assertSame($state, json_decode((string) json_encode($state), true));
    }

    #[Test]
    public function it_fails_loudly_when_a_store_never_runs_the_callback(): void
    {
        $distribution = WeightedDistribution::make('broken', new BrokenStore)->target('a', 1);

        $this->expectException(StoreFailure::class);
        $this->expectExceptionMessage('The store did not run the update callback for [broken].');

        $distribution->allocate();
    }

    #[Test]
    public function it_starts_fresh_when_the_saved_state_is_unreadable(): void
    {
        $store = new ArrayStore;
        $store->update('junk', fn (): array => ['position' => 'x', 'counts' => 'nope', 'totals' => [1, 'a'], 'usage' => 5]);

        $distribution = WeightedDistribution::make('junk', $store)->target('a', 1)->target('b', 1);

        $this->assertSame(['a', 'b'], $this->pick($distribution, 2));
    }

    #[Test]
    public function it_refuses_state_that_contradicts_the_targets(): void
    {
        $store = new ArrayStore;
        $store->update('tampered', fn (): array => [
            'signature' => (string) json_encode([['a', 1], ['b', 1]]),
            'position' => 0,
            'counts' => ['a' => 5, 'b' => 5],
        ]);

        $distribution = WeightedDistribution::make('tampered', $store)->target('a', 1)->target('b', 1);

        $this->expectException(StoreFailure::class);
        $this->expectExceptionMessage('The stored state does not match the registered targets; call reset() to start over.');

        $distribution->allocate();
    }

    #[Test]
    public function it_recovers_from_inconsistent_state_after_a_reset(): void
    {
        $store = new ArrayStore;
        $store->update('tampered', fn (): array => [
            'signature' => (string) json_encode([['a', 1], ['b', 1]]),
            'position' => 0,
            'counts' => ['a' => 5, 'b' => 5],
        ]);

        $distribution = WeightedDistribution::make('tampered', $store)->target('a', 1)->target('b', 1);

        $this->assertSame(['a', 'b'], $this->pick($distribution->reset(), 2));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidStates(): array
    {
        return [
            'negative position' => [['position' => -1], 'a negative cycle position'],
            'negative count' => [['counts' => ['a' => -1]], 'a negative count for [a]'],
            'negative total' => [['totals' => ['a' => -5]], 'a negative count for [a]'],
            'negative cap usage' => [['usage' => ['a' => ['window' => 1, 'used' => -3]]], 'negative cap usage for [a]'],
            'totals at the limit' => [['totals' => ['a' => PHP_INT_MAX]], 'the lifetime totals are too large to count'],
            'totals summing past the limit' => [['totals' => ['a' => PHP_INT_MAX - 5, 'b' => 5]], 'the lifetime totals are too large to count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[Test]
    #[DataProvider('invalidStates')]
    public function it_refuses_saved_numbers_that_cannot_be_right(array $state, string $problem): void
    {
        $store = new ArrayStore;
        $store->update('tampered', fn (): array => $state);

        $distribution = WeightedDistribution::make('tampered', $store)->target('a', 1)->target('b', 1);

        foreach (['allocate', 'peek', 'report'] as $method) {
            try {
                $distribution->{$method}();
                $this->fail("Expected {$method}() to refuse the state.");
            } catch (StoreFailure $e) {
                $this->assertSame("The stored state is invalid: {$problem}; call reset() to start over.", $e->getMessage());
            }
        }

        $this->assertSame(['a', 'b'], $this->pick($distribution->reset(), 2));
    }

    /**
     * Cycles for the targets a, b and c with equal weights (a cycle of 3).
     *
     * @return array<string, array{int, array<string, int>, array<string, int>}>
     */
    public static function cyclesThatDoNotAddUp(): array
    {
        $plenty = ['a' => 9, 'b' => 9, 'c' => 9];

        return [
            'position past the cycle' => [3, ['a' => 1, 'b' => 1, 'c' => 1], $plenty],
            'counts more than the position' => [1, ['a' => 1, 'b' => 1], $plenty],
            'counts less than the position' => [1, [], $plenty],
            'a count for a target not in play' => [1, ['d' => 1], $plenty],
            'one target twice in a three-slot cycle' => [2, ['a' => 2], $plenty],
            'more picks this cycle than in its lifetime' => [1, ['a' => 1], ['a' => 0]],
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $totals
     */
    #[Test]
    #[DataProvider('cyclesThatDoNotAddUp')]
    public function it_refuses_an_inconsistent_cycle(int $position, array $counts, array $totals): void
    {
        $store = new ArrayStore;
        $store->update('tampered', fn (): array => [
            'signature' => (string) json_encode([['a', 1], ['b', 1], ['c', 1]]),
            'position' => $position,
            'counts' => $counts,
            'totals' => $totals,
        ]);

        $distribution = WeightedDistribution::make('tampered', $store)->target('a', 1)->target('b', 1)->target('c', 1);

        $this->expectException(StoreFailure::class);
        $this->expectExceptionMessage('The stored state does not match the registered targets; call reset() to start over.');

        $distribution->peek();
    }

    #[Test]
    public function it_accepts_every_state_the_rule_itself_saves(): void
    {
        $store = new ArrayStore;
        $distribution = WeightedDistribution::make('honest', $store)->target('a', 5)->target('b', 3)->target('c', 2);

        // Every mid-cycle state the scheduler writes must pass its own consistency check on the next read.
        for ($i = 0; $i < 30; $i++) {
            $distribution->peek();
            $distribution->allocate();
        }

        $this->assertSame(30, $distribution->report()->allocated);
    }
}
