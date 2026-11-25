<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests;

use Keaton\WeightedDistribution\WeightedDistributionServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            WeightedDistributionServiceProvider::class,
        ];
    }
}
