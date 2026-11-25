<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Engine;

use Keaton\WeightedDistribution\Decision;

/**
 * @internal
 */
final readonly class Outcome
{
    public function __construct(
        public Decision $decision,
        public State $state,
    ) {
        //
    }
}
