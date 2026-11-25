<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Unit;

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\Exceptions\InvalidCap;
use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\Exceptions\InvalidTarget;
use Keaton\WeightedDistribution\Exceptions\NoTargetAvailable;
use Keaton\WeightedDistribution\Tests\Concerns\MeasuresFairness;
use Keaton\WeightedDistribution\Tests\Support\FakeClock;
use Keaton\WeightedDistribution\WeightedDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TargetRegistrationTest extends TestCase
{
    use MeasuresFairness;

    #[Test]
    public function it_refuses_a_duplicate_target(): void
    {
        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('The target [a] is already registered.');

        WeightedDistribution::make()->target('a', 1)->target('a', 2);
    }

    #[Test]
    public function it_refuses_a_zero_weight(): void
    {
        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('The weight of target [a] must be a positive integer, [0] given.');

        WeightedDistribution::make()->target('a', 0);
    }

    #[Test]
    public function it_refuses_a_negative_weight(): void
    {
        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('The weight of target [a] must be a positive integer, [-3] given.');

        WeightedDistribution::make()->target('a', -3);
    }

    #[Test]
    public function it_refuses_a_blank_target_name(): void
    {
        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('A target name cannot be empty.');

        WeightedDistribution::make()->target('  ', 1);
    }

    #[Test]
    public function it_refuses_a_blank_distribution_name(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('A distribution name cannot be empty.');

        WeightedDistribution::make('');
    }

    #[Test]
    public function it_refuses_to_allocate_without_targets(): void
    {
        $this->expectException(NoTargetAvailable::class);
        $this->expectExceptionMessage('No targets are registered; register at least one with target() first.');

        WeightedDistribution::make()->allocate();
    }

    #[Test]
    public function it_has_no_capacity_without_targets(): void
    {
        $this->assertFalse(WeightedDistribution::make()->hasCapacity());
    }

    #[Test]
    public function it_registers_targets_from_their_config_form(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())->targets([
            'alpha' => ['weight' => 2],
            'beta' => ['weight' => 1, 'cap' => ['limit' => 1, 'per' => 'day']],
            'gamma' => ['weight' => 1, 'cap' => Cap::perHour(5)],
        ]);

        $report = $distribution->report();

        $this->assertSame(['alpha', 'beta', 'gamma'], array_keys($report->targets));
        $this->assertSame(1, $report->usage('beta')->limit);
        $this->assertSame(5, $report->usage('gamma')->limit);
        $this->assertNull($report->usage('alpha')->limit);
    }

    #[Test]
    public function it_refuses_a_config_target_without_a_weight(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.targets.alpha] definition must be an array with an integer [weight].');

        WeightedDistribution::make()->targets(['alpha' => 'five']);
    }

    #[Test]
    public function it_refuses_a_config_target_with_a_malformed_cap(): void
    {
        $this->expectException(InvalidCap::class);

        WeightedDistribution::make()->targets(['alpha' => ['weight' => 1, 'cap' => ['limit' => 5]]]);
    }

    #[Test]
    public function it_refuses_a_config_target_whose_cap_is_not_an_array(): void
    {
        $this->expectException(InvalidConfiguration::class);

        WeightedDistribution::make()->targets(['alpha' => ['weight' => 1, 'cap' => 'daily']]);
    }

    #[Test]
    public function it_refuses_to_describe_a_target_that_is_not_registered(): void
    {
        $report = WeightedDistribution::make()->target('a', 1)->report();

        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('The target [zzz] is not registered.');

        $report->usage('zzz');
    }

    #[Test]
    public function it_treats_numeric_looking_names_as_names(): void
    {
        $distribution = WeightedDistribution::make()->target('1', 1)->target('2', 1);

        $this->assertSame(['1', '2', '1', '2'], $this->pick($distribution, 4));
        $this->assertSame(2, $distribution->report()->usage('1')->allocated);
    }

    #[Test]
    public function it_refuses_a_config_target_with_an_unknown_key(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The [weighted_distribution.targets.alpha] definition has unknown keys [capp]; a target takes [weight] and an optional [cap].');

        WeightedDistribution::make()->targets(['alpha' => ['weight' => 5, 'capp' => ['limit' => 10, 'per' => 'day']]]);
    }

    #[Test]
    public function it_refuses_a_target_name_that_is_not_valid_utf8(): void
    {
        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('A target name must be valid UTF-8.');

        WeightedDistribution::make()->target("caf\xE9", 1);
    }

    #[Test]
    public function it_accepts_any_valid_utf8_name(): void
    {
        $distribution = WeightedDistribution::make()->target('café', 1)->target('东京', 1);

        $this->assertSame(['café', '东京'], [$distribution->allocate()->target, $distribution->allocate()->target]);
    }
}
