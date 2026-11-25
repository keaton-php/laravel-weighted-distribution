<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Unit;

use Keaton\WeightedDistribution\Tests\Concerns\MeasuresFairness;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The package's promise, checked directly against the bound: fairness holds at every
 * step, not only on average. No framework is booted for any of this.
 */
class FairnessTest extends TestCase
{
    use MeasuresFairness;

    /**
     * @return array<string, array{list<int>}>
     */
    public static function weightSets(): array
    {
        return [
            'one to one' => [[1, 1]],
            'two to one' => [[2, 1]],
            'three equal' => [[1, 1, 1]],
            'heavy head' => [[5, 1, 1]],
            'one big, three small' => [[3, 1, 1, 1]],
            'seven, two, one' => [[7, 2, 1]],
            'arithmetic run' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
            'fibonacci' => [[13, 8, 5, 3, 2, 1]],
            'dominant target' => [[97, 1, 1, 1]],
            'ninety-nine to one' => [[99, 1]],
            'percentages' => [[50, 30, 20]],
            'common factor' => [[10, 10, 20]],
            'twelve equal' => [[1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]],
            'nine tiny and one huge' => [[1, 1, 1, 1, 1, 1, 1, 1, 1, 50]],
            'single target' => [[7]],
        ];
    }

    /**
     * Forty reproducible pseudo-random weight sets of 2 to 10 targets.
     *
     * @return array<string, array{list<int>}>
     */
    public static function generatedWeightSets(): array
    {
        $seed = 20261105;

        $next = static function () use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return $seed >> 8;
        };

        $sets = [];

        for ($i = 1; $i <= 40; $i++) {
            $members = 2 + $next() % 9;
            $weights = [];

            for ($j = 0; $j < $members; $j++) {
                $weights[] = 1 + $next() % 40;
            }

            $sets["generated set {$i}"] = [$weights];
        }

        return $sets;
    }

    /**
     * @param  list<int>  $weights
     */
    #[Test]
    #[DataProvider('weightSets')]
    #[DataProvider('generatedWeightSets')]
    public function it_keeps_every_target_within_one_of_its_ideal_share_at_every_step(array $weights): void
    {
        $sequence = $this->pick($this->distributionFor($weights), 3 * array_sum($weights));

        $this->assertFair($sequence, $this->weightMap($weights));
        $this->assertLessThan(1.0, $this->maxDeviation($sequence, $this->weightMap($weights)));
    }

    /**
     * @param  list<int>  $weights
     */
    #[Test]
    #[DataProvider('weightSets')]
    #[DataProvider('generatedWeightSets')]
    public function it_gives_each_target_exactly_its_weight_over_a_full_cycle(array $weights): void
    {
        $sequence = $this->pick($this->distributionFor($weights), array_sum($weights));

        $counts = array_count_values($sequence);

        foreach ($this->weightMap($weights) as $name => $weight) {
            $this->assertSame($weight, $counts[$name] ?? 0, "{$name} did not receive exactly its weight");
        }
    }

    /**
     * @param  list<int>  $weights
     */
    #[Test]
    #[DataProvider('weightSets')]
    public function it_repeats_the_same_sequence_every_cycle(array $weights): void
    {
        $total = array_sum($weights);
        $sequence = $this->pick($this->distributionFor($weights), 2 * $total);

        $this->assertSame(array_slice($sequence, 0, $total), array_slice($sequence, $total));
    }

    #[Test]
    public function it_stays_fair_across_thousands_of_allocations(): void
    {
        $weights = [11, 7, 5, 3, 2];

        $sequence = $this->pick($this->distributionFor($weights), 5000);

        $this->assertFair($sequence, $this->weightMap($weights));
    }

    #[Test]
    public function it_would_fail_if_the_allocator_ignored_the_weights(): void
    {
        $weights = ['t0' => 5, 't1' => 1, 't2' => 1];

        // Plain rotation: every target served equally often, whatever its weight.
        $rotation = array_slice(array_merge(...array_fill(0, 7, ['t0', 't1', 't2'])), 0, 21);

        $this->assertGreaterThan(1.0, $this->maxDeviation($rotation, $weights));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('After');

        $this->assertFair($rotation, $weights);
    }

    #[Test]
    public function it_would_fail_if_the_allocator_were_only_right_on_average(): void
    {
        $weights = ['t0' => 5, 't1' => 1, 't2' => 1];

        // Exactly 5:1:1 in every block of seven, but five in a row for t0 drifts by 1.43.
        $bunched = array_merge(...array_fill(0, 3, ['t0', 't0', 't0', 't0', 't0', 't1', 't2']));

        $this->assertGreaterThan(1.0, $this->maxDeviation($bunched, $weights));

        $this->expectException(AssertionFailedError::class);

        $this->assertFair($bunched, $weights);
    }

    #[Test]
    public function it_would_fail_if_the_allocator_always_served_the_heaviest_target(): void
    {
        $weights = ['t0' => 3, 't1' => 1];
        $greedy = array_fill(0, 20, 't0');

        $this->assertGreaterThan(1.0, $this->maxDeviation($greedy, $weights));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('After');

        $this->assertFair($greedy, $weights);
    }
}
