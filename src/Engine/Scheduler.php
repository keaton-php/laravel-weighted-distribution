<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Engine;

use Keaton\WeightedDistribution\Decision;
use Keaton\WeightedDistribution\Exceptions\InvalidTarget;
use Keaton\WeightedDistribution\Exceptions\NoTargetAvailable;
use Keaton\WeightedDistribution\Exceptions\StoreFailure;
use Keaton\WeightedDistribution\Reason;
use Keaton\WeightedDistribution\Report;
use Keaton\WeightedDistribution\Standing;
use Keaton\WeightedDistribution\Status;
use Keaton\WeightedDistribution\Target;
use Keaton\WeightedDistribution\Usage;

/**
 * The allocation algorithm, free of any framework and of any storage.
 *
 * It is Tijdeman's solution to the chairman assignment problem (1980), seen
 * as deficit round-robin. Per cycle of W slots (W is the sum of the weights
 * after dividing out their common factor) every target keeps a deficit:
 *
 *     owed = slot * (weight / W) - picks so far
 *
 * A target may only be served while it is owed at least 1 / (2 * (m - 1))
 * of an allocation (m is the number of targets in play), which stops it
 * running ahead of its share. Of the targets that may be served, the one
 * whose next pick is due soonest wins:
 *
 *     deadline = (picks + 1 - 1 / (2 * (m - 1))) / (weight / W)
 *
 * Tijdeman proved that this keeps every target within 1 - 1 / (2 * (m - 1))
 * of its ideal count after every single allocation, not just on average.
 * That bound is below 1, so no target is ever off by a whole allocation.
 * After W slots every target has had exactly its weight (reduced), so the
 * cycle simply starts again.
 *
 * Everything below is integer arithmetic scaled by W so that no rounding
 * can move a comparison; floats only appear in the report.
 *
 * The guarantee holds while the set of targets in play stays the same. When
 * a target saturates, comes back after a window rolls, or is registered, a
 * new cycle starts for the new set: the bound then holds from that point on.
 *
 * @internal
 */
final class Scheduler
{
    /**
     * @param  list<Target>  $targets  every registered target, in registration order
     */
    public function decide(array $targets, State $state, int $now): Outcome
    {
        if ($targets === []) {
            throw NoTargetAvailable::noTargets();
        }

        $usage = $this->usage($targets, $state, $now);

        $active = array_values(array_filter(
            $targets,
            fn (Target $target): bool => ! $this->saturated($target, $usage),
        ));

        if ($active === []) {
            throw NoTargetAvailable::allSaturated(
                array_map(fn (Target $target): string => $target->name, $targets),
                $this->earliestReset($targets, $now),
            );
        }

        // A different set of targets in play means a new cycle.
        $signature = $this->signature($active);
        $restart = $signature !== $state->signature;
        $counts = $restart ? [] : $state->counts;
        $slot = ($restart ? 0 : $state->position) + 1;

        $weights = $this->reduce($active);
        $members = count($active);
        $slack = 2 * ($members - 1);
        $total = $this->total($weights, $slack);

        if (! $restart) {
            $this->assertConsistent($active, $weights, $counts, $state->totals, $state->position, $total);
        }

        $owed = [];
        $eligible = [];
        $best = null;
        $bestUrgency = 0;

        foreach ($active as $index => $target) {
            $picks = $counts[$target->name] ?? 0;
            $owed[$index] = $slot * $weights[$index] - $picks * $total;

            // Not owed enough yet: serving it now would put it ahead of its share.
            $eligible[$index] = $members === 1 || $slack * $owed[$index] >= $total;

            if (! $eligible[$index]) {
                continue;
            }

            // Earliest deadline wins; compared as fractions by cross-multiplying.
            // On a tie the target registered first keeps the slot.
            $urgency = $slack * ($picks + 1) - 1;

            if ($best === null || $urgency * $weights[$best] < $bestUrgency * $weights[$index]) {
                $best = $index;
                $bestUrgency = $urgency;
            }
        }

        // Cannot happen with a consistent state: the owed amounts always sum to one slot.
        if ($best === null) {
            throw StoreFailure::inconsistentState();
        }

        $chosen = $active[$best];
        $standings = $this->standings($targets, $active, $weights, $owed, $eligible, $best, $counts, $usage, $total, $now);

        $counts[$chosen->name] = ($counts[$chosen->name] ?? 0) + 1;
        $position = $slot;

        // A full cycle leaves every target exactly on its share, so it starts clean.
        if ($position >= $total) {
            $position = 0;
            $counts = [];
        }

        $totals = $state->totals;
        $totals[$chosen->name] = ($totals[$chosen->name] ?? 0) + 1;

        if ($chosen->cap !== null) {
            $window = $usage[$chosen->name];
            $usage[$chosen->name] = ['window' => $window['window'], 'used' => $window['used'] + 1];
        }

        return new Outcome(
            new Decision(
                target: $chosen->name,
                reason: $members === 1 ? Reason::OnlyEligible : Reason::Deficit,
                sequence: array_sum($totals),
                position: $slot,
                cycle: $total,
                at: $now,
                standings: $standings,
            ),
            new State($signature, $position, $counts, $totals, $usage),
        );
    }

    /**
     * A cycle in progress must be structurally consistent: a position inside
     * the cycle, picks only for these targets that add up to it, every target
     * within the proven bound of its share, and no more picks this cycle than
     * the target's lifetime total. (It does not replay the cycle to prove this
     * exact rule produced it.)
     *
     * @param  list<Target>  $active
     * @param  list<int>  $weights
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $totals
     */
    protected function assertConsistent(array $active, array $weights, array $counts, array $totals, int $position, int $total): void
    {
        $names = array_map(fn (Target $target): string => $target->name, $active);

        if ($position >= $total || array_diff(array_keys($counts), $names) !== [] || array_sum($counts) !== $position) {
            throw StoreFailure::inconsistentState();
        }

        // |count * W - position * weight| / W <= 1 - 1/p, with p = 2(m - 1); a lone target is exact.
        $p = 2 * (count($active) - 1);

        foreach ($active as $index => $target) {
            $count = $counts[$target->name] ?? 0;
            $drift = abs($count * $total - $position * $weights[$index]);

            if ($count > ($totals[$target->name] ?? 0) || ($p === 0 ? $drift !== 0 : $drift * $p > ($p - 1) * $total)) {
                throw StoreFailure::inconsistentState();
            }
        }
    }

    /**
     * @param  list<Target>  $targets
     */
    public function report(string $distribution, array $targets, State $state, int $now): Report
    {
        $usage = $this->usage($targets, $state, $now);

        $weightSum = array_sum(array_map(fn (Target $target): int => $target->weight, $targets));
        // Every allocation the store holds, including any to a target no longer registered.
        $allocated = array_sum($state->totals);

        $rows = [];

        foreach ($targets as $target) {
            $received = $state->totals[$target->name] ?? 0;

            $rows[$target->name] = new Usage(
                target: $target->name,
                weight: $target->weight,
                share: $target->weight / $weightSum,
                allocated: $received,
                actual: $allocated > 0 ? $received / $allocated : 0.0,
                used: $target->cap === null ? null : $usage[$target->name]['used'],
                limit: $target->cap?->limit,
                resetsAt: $target->cap?->resetsAt($now),
                saturated: $this->saturated($target, $usage),
            );
        }

        return new Report($distribution, $now, $allocated, $rows);
    }

    /**
     * @param  list<Target>  $targets
     * @param  list<Target>  $active
     * @param  list<int>  $weights
     * @param  array<int, int>  $owed
     * @param  array<int, bool>  $eligible
     * @param  array<string, int>  $counts
     * @param  array<string, array{window: int, used: int}>  $usage
     * @return array<array-key, Standing>
     */
    protected function standings(array $targets, array $active, array $weights, array $owed, array $eligible, int $best, array $counts, array $usage, int $total, int $now): array
    {
        $indexes = [];

        foreach ($active as $index => $target) {
            $indexes[$target->name] = $index;
        }

        $standings = [];

        foreach ($targets as $target) {
            $cap = $target->cap;
            $used = $cap === null ? null : $usage[$target->name]['used'];

            if (! isset($indexes[$target->name])) {
                $standings[$target->name] = new Standing($target->name, $target->weight, Status::Saturated, 0.0, 0, 0.0, $used, $cap?->limit, $cap?->resetsAt($now));

                continue;
            }

            $index = $indexes[$target->name];

            $status = match (true) {
                $index === $best => Status::Chosen,
                $eligible[$index] => Status::Candidate,
                default => Status::Waiting,
            };

            $standings[$target->name] = new Standing(
                $target->name,
                $target->weight,
                $status,
                $weights[$index] / $total,
                $counts[$target->name] ?? 0,
                $owed[$index] / $total,
                $used,
                $cap?->limit,
                $cap?->resetsAt($now),
            );
        }

        return $standings;
    }

    /**
     * Cap usage for the window that $now falls in; a stale window counts as empty.
     *
     * @param  list<Target>  $targets
     * @return array<string, array{window: int, used: int}>
     */
    protected function usage(array $targets, State $state, int $now): array
    {
        $usage = [];

        foreach ($targets as $target) {
            if ($target->cap === null) {
                continue;
            }

            $window = $target->cap->windowId($now);
            $stored = $state->usage[$target->name] ?? null;

            $usage[$target->name] = [
                'window' => $window,
                'used' => $stored !== null && $stored['window'] === $window ? $stored['used'] : 0,
            ];
        }

        return $usage;
    }

    /**
     * @param  array<string, array{window: int, used: int}>  $usage
     */
    protected function saturated(Target $target, array $usage): bool
    {
        return $target->cap !== null && ($usage[$target->name]['used'] ?? 0) >= $target->cap->limit;
    }

    /**
     * @param  list<Target>  $targets
     */
    protected function earliestReset(array $targets, int $now): int
    {
        $resets = [];

        foreach ($targets as $target) {
            if ($target->cap !== null) {
                $resets[] = $target->cap->resetsAt($now);
            }
        }

        return $resets === [] ? $now : min($resets);
    }

    /**
     * @param  list<Target>  $active
     */
    protected function signature(array $active): string
    {
        return json_encode(
            array_map(fn (Target $target): array => [$target->name, $target->weight], $active),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Weights divided by their greatest common divisor: the same sequence, a shorter cycle.
     *
     * @param  list<Target>  $active
     * @return list<int>
     */
    protected function reduce(array $active): array
    {
        $divisor = 0;

        foreach ($active as $target) {
            $divisor = $this->gcd($divisor, $target->weight);
        }

        return array_map(fn (Target $target): int => intdiv($target->weight, $divisor), $active);
    }

    /**
     * The cycle length. The comparisons in decide() multiply up to
     * slack * total * total, so refuse weights that would overflow an int
     * rather than let PHP turn the arithmetic into floats.
     *
     * @param  list<int>  $weights
     */
    protected function total(array $weights, int $slack): int
    {
        $total = 0;

        foreach ($weights as $weight) {
            if ($weight > PHP_INT_MAX - $total) {
                throw InvalidTarget::weightsTooLarge();
            }

            $total += $weight;
        }

        if ($total > intdiv(intdiv(PHP_INT_MAX, max(1, $slack)), $total)) {
            throw InvalidTarget::weightsTooLarge();
        }

        return $total;
    }

    protected function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
