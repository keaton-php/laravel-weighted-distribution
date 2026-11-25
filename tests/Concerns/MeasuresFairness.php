<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Concerns;

use Keaton\WeightedDistribution\WeightedDistribution;

/**
 * The yardstick for the package's one promise: after every single allocation,
 * each target's count is within 1 of its ideal share (N * weight / total).
 * All comparisons are integer arithmetic scaled by the weight total, so a
 * rounding error can never hide a failure.
 */
trait MeasuresFairness
{
    /**
     * @param  list<int>  $weights
     * @return array<string, int>
     */
    protected function weightMap(array $weights): array
    {
        $map = [];

        foreach ($weights as $index => $weight) {
            $map["t{$index}"] = $weight;
        }

        return $map;
    }

    /**
     * @param  list<int>  $weights
     */
    protected function distributionFor(array $weights): WeightedDistribution
    {
        $distribution = WeightedDistribution::make('fairness');

        foreach ($this->weightMap($weights) as $name => $weight) {
            $distribution->target($name, $weight);
        }

        return $distribution;
    }

    /**
     * @return list<string>
     */
    protected function pick(WeightedDistribution $distribution, int $count): array
    {
        $sequence = [];

        for ($i = 0; $i < $count; $i++) {
            $sequence[] = $distribution->allocate()->target;
        }

        return $sequence;
    }

    /**
     * Fail unless every target stays within 1 - 1/(2(m-1)) of its ideal count
     * after every allocation in the sequence (a lone target must be exact).
     *
     * @param  list<string>  $sequence
     * @param  array<string, int>  $weights
     */
    protected function assertFair(array $sequence, array $weights, string $context = ''): void
    {
        $total = array_sum($weights);
        $members = count($weights);
        $counts = array_fill_keys(array_keys($weights), 0);

        // |drift| / total must not exceed (p - 1) / p, where p = 2(m - 1).
        $p = max(1, 2 * ($members - 1));
        $allowed = $members === 1 ? 0 : ($p - 1) * $total;

        foreach ($sequence as $index => $target) {
            $counts[$target]++;
            $slot = $index + 1;

            foreach ($weights as $name => $weight) {
                $drift = abs($counts[$name] * $total - $slot * $weight);

                if ($drift * $p > $allowed) {
                    $this->fail(sprintf(
                        '%sAfter %d allocations %s has %d, ideal is %.4f (off by %.4f, the limit is below 1).',
                        $context === '' ? '' : "{$context}: ",
                        $slot,
                        $name,
                        $counts[$name],
                        $slot * $weight / $total,
                        $drift / $total,
                    ));
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The largest distance, in allocations, between any target's count and its
     * ideal count at any point in the sequence.
     *
     * @param  list<string>  $sequence
     * @param  array<string, int>  $weights
     */
    protected function maxDeviation(array $sequence, array $weights): float
    {
        $total = array_sum($weights);
        $counts = array_fill_keys(array_keys($weights), 0);
        $worst = 0;

        foreach ($sequence as $index => $target) {
            $counts[$target]++;

            foreach ($weights as $name => $weight) {
                $worst = max($worst, abs($counts[$name] * $total - ($index + 1) * $weight));
            }
        }

        return $worst / $total;
    }
}
