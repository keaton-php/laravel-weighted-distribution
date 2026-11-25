<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

final readonly class Decision
{
    /**
     * @param  array<array-key, Standing>  $standings  every registered target, keyed by name, in registration order
     *                                                 (PHP stores a numeric name such as '12' as the integer key 12)
     */
    public function __construct(
        public string $target,
        public Reason $reason,
        // Lifetime allocation number for this distribution, this one included.
        public int $sequence,
        // Slot inside the current cycle (1-based) and how many slots a cycle has.
        public int $position,
        public int $cycle,
        // Unix timestamp the decision was made at.
        public int $at,
        public array $standings,
    ) {
        //
    }

    public function standing(): Standing
    {
        return $this->standings[$this->target];
    }

    /**
     * One plain-English paragraph saying why this target won.
     */
    public function explain(): string
    {
        $chosen = $this->standing();
        $eligible = 0;
        $waiting = [];
        $saturated = [];

        foreach ($this->standings as $standing) {
            match ($standing->status) {
                Status::Chosen, Status::Candidate => $eligible++,
                Status::Waiting => $waiting[] = $standing->target,
                Status::Saturated => $saturated[] = $this->describeSaturated($standing),
            };
        }

        $sentences = [match ($this->reason) {
            Reason::OnlyEligible => sprintf(
                "Allocation #%d: '%s' was chosen because it was the only target with capacity left.",
                $this->sequence,
                $this->target,
            ),
            Reason::Deficit => sprintf(
                "Allocation #%d: '%s' was chosen. It was owed %.2f of an allocation (slot %d of %d in this cycle) and %s.",
                $this->sequence,
                $this->target,
                $chosen->owed,
                $this->position,
                $this->cycle,
                $eligible === 1 ? 'was the only target due one' : "had the earliest deadline of the {$eligible} targets due one",
            ),
        }];

        if ($waiting !== []) {
            $sentences[] = 'Not due one yet: '.implode(', ', $waiting).'.';
        }

        if ($saturated !== []) {
            $sentences[] = 'At their cap: '.implode(', ', $saturated).'.';
        }

        return implode(' ', $sentences);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'reason' => $this->reason->value,
            'sequence' => $this->sequence,
            'position' => $this->position,
            'cycle' => $this->cycle,
            'at' => $this->at,
            // A list, in registration order, so it is a JSON array whatever the names are; each entry names its target.
            'standings' => array_values(array_map(fn (Standing $standing): array => $standing->toArray(), $this->standings)),
        ];
    }

    protected function describeSaturated(Standing $standing): string
    {
        $until = $standing->resetsAt === null ? '' : ' until '.gmdate('Y-m-d H:i:s', $standing->resetsAt).' UTC';

        return sprintf('%s (%d/%d%s)', $standing->target, $standing->used ?? 0, $standing->limit ?? 0, $until);
    }
}
