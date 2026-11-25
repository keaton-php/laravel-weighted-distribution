<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

use Keaton\WeightedDistribution\Exceptions\InvalidTarget;

final readonly class Report
{
    /**
     * @param  array<array-key, Usage>  $targets  every registered target, keyed by name, in registration order
     *                                            (PHP stores a numeric name such as '12' as the integer key 12)
     */
    public function __construct(
        public string $distribution,
        public int $at,
        // Allocations handed out over the life of the distribution.
        public int $allocated,
        public array $targets,
    ) {
        //
    }

    public function usage(string $target): Usage
    {
        return $this->targets[$target] ?? throw InvalidTarget::unknown($target);
    }

    public function isSaturated(string $target): bool
    {
        return $this->usage($target)->saturated;
    }

    /**
     * @return array<array-key, Usage>
     */
    public function saturated(): array
    {
        return array_filter($this->targets, fn (Usage $usage): bool => $usage->saturated);
    }

    /**
     * @return array<array-key, Usage>
     */
    public function available(): array
    {
        return array_filter($this->targets, fn (Usage $usage): bool => ! $usage->saturated);
    }
}
