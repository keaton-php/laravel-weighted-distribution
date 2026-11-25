<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

enum Reason: string
{
    // Chosen on its deficit: owed an allocation, with the earliest deadline.
    case Deficit = 'deficit';

    // It was the only target in play: the others were at their cap, or there are no others.
    case OnlyEligible = 'only-eligible';
}
