<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

/**
 * One target as it stood at the moment of a decision, before the pick was applied.
 */
final readonly class Standing
{
    public function __construct(
        public string $target,
        public int $weight,
        public Status $status,
        // Fraction of the traffic this target is due among the targets that were not saturated.
        public float $share,
        // Picks it received in the current cycle before this decision.
        public int $allocated,
        // How far behind its ideal share it was, in allocations (this slot included).
        public float $owed,
        public ?int $used = null,
        public ?int $limit = null,
        public ?int $resetsAt = null,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'weight' => $this->weight,
            'status' => $this->status->value,
            'share' => $this->share,
            'allocated' => $this->allocated,
            'owed' => $this->owed,
            'used' => $this->used,
            'limit' => $this->limit,
            'resets_at' => $this->resetsAt,
        ];
    }
}
