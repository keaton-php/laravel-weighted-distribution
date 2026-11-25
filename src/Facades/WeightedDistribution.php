<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Facades;

use Illuminate\Support\Facades\Facade;
use Keaton\WeightedDistribution\WeightedDistribution as Distribution;

/**
 * @method static \Keaton\WeightedDistribution\Decision allocate()
 * @method static \Keaton\WeightedDistribution\Decision peek()
 * @method static bool hasCapacity()
 * @method static \Keaton\WeightedDistribution\Report report()
 * @method static \Keaton\WeightedDistribution\WeightedDistribution reset()
 * @method static string name()
 *
 * The distribution built from config/weighted_distribution.php. It is one
 * shared instance per application (and per long-lived worker under Octane or
 * a queue), so its targets belong in the config, not in runtime calls.
 *
 * @see Distribution
 */
class WeightedDistribution extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Distribution::class;
    }
}
