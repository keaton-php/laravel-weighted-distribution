<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

enum Status: string
{
    // The target that received the allocation.
    case Chosen = 'chosen';

    // Owed an allocation, but another target had the earlier deadline.
    case Candidate = 'candidate';

    // Not owed enough yet: serving it now could put it ahead of its share.
    case Waiting = 'waiting';

    // Reached its cap for the current window.
    case Saturated = 'saturated';
}
