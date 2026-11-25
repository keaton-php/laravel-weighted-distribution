<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Exceptions;

use RuntimeException;

class NoTargetAvailable extends RuntimeException
{
    public static function noTargets(): self
    {
        return new self('No targets are registered; register at least one with target() first.');
    }

    /**
     * @param  list<string>  $targets
     */
    public static function allSaturated(array $targets, int $resetsAt): self
    {
        $names = implode(', ', $targets);
        $when = gmdate('Y-m-d H:i:s', $resetsAt);

        return new self("Every target is at its cap ({$names}); the earliest window resets at {$when} UTC.");
    }
}
