<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Support;

use Closure;

final class FakeClock
{
    // 2023-11-15 00:00:00 UTC: on a day, hour and minute boundary, so windows are easy to reason about.
    public const START = 1_700_006_400;

    public function __construct(public int $now = self::START)
    {
        //
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function time(): int
    {
        return $this->now;
    }

    /**
     * @return Closure(): int
     */
    public function closure(): Closure
    {
        return $this->time(...);
    }
}
