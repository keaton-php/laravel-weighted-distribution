<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

final readonly class Usage
{
    public function __construct(
        public string $target,
        public int $weight,
        // The share it is configured to receive: its weight over the sum of all weights.
        public float $share,
        // Allocations it has received over the life of the distribution.
        public int $allocated,
        // The share it has actually received so far (0.0 before the first allocation).
        public float $actual,
        // Allocations inside the current cap window, and the cap itself; null when uncapped.
        public ?int $used,
        public ?int $limit,
        public ?int $resetsAt,
        public bool $saturated,
    ) {
        //
    }

    public function remaining(): ?int
    {
        if ($this->limit === null || $this->used === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }
}
