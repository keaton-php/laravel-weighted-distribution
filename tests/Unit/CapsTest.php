<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Unit;

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\Exceptions\InvalidCap;
use Keaton\WeightedDistribution\Exceptions\NoTargetAvailable;
use Keaton\WeightedDistribution\Reason;
use Keaton\WeightedDistribution\Status;
use Keaton\WeightedDistribution\Tests\Concerns\MeasuresFairness;
use Keaton\WeightedDistribution\Tests\Support\FakeClock;
use Keaton\WeightedDistribution\WeightedDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CapsTest extends TestCase
{
    use MeasuresFairness;

    #[Test]
    public function it_never_exceeds_a_cap_inside_its_window(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(3))
            ->target('b', 1);

        $counts = array_count_values($this->pick($distribution, 12));

        $this->assertSame(3, $counts['a']);
        $this->assertSame(9, $counts['b']);
    }

    #[Test]
    public function it_holds_a_cap_in_every_window_over_a_long_run(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 3, Cap::perMinute(10))
            ->target('b', 1);

        $perWindow = [];

        for ($second = 0; $second < 600; $second++) {
            $clock->now = FakeClock::START + $second;

            if ($distribution->allocate()->target === 'a') {
                $window = intdiv($clock->now, 60);
                $perWindow[$window] = ($perWindow[$window] ?? 0) + 1;
            }
        }

        $this->assertCount(10, $perWindow);

        foreach ($perWindow as $served) {
            $this->assertSame(10, $served);
        }
    }

    #[Test]
    public function it_lets_a_target_back_in_when_its_window_rolls_over(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(1))
            ->target('b', 1);

        $this->assertSame(['a', 'b', 'b'], $this->pick($distribution, 3));

        $clock->advance(Cap::HOUR);

        $this->assertSame('a', $distribution->allocate()->target);
    }

    #[Test]
    public function it_aligns_windows_to_the_clock_not_to_the_first_allocation(): void
    {
        $clock = new FakeClock(FakeClock::START + Cap::HOUR - 1);
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(1))
            ->target('b', 1);

        $this->assertSame('a', $distribution->allocate()->target);
        $this->assertSame('b', $distribution->allocate()->target);

        // One second later the window has rolled, even though an hour has not passed since the first pick.
        $clock->advance(1);

        $this->assertSame('a', $distribution->allocate()->target);
    }

    #[Test]
    public function it_keeps_the_remaining_targets_fair_once_one_is_capped(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perDay(2))
            ->target('b', 3)
            ->target('c', 1);

        $sequence = $this->pick($distribution, 30);

        $served = array_keys(array_filter($sequence, fn (string $target): bool => $target === 'a'));
        $this->assertCount(2, $served);

        // After the cap is hit, the picks that follow are a fresh, fair 3:1 split between b and c.
        $tail = array_slice($sequence, $served[1] + 1);

        $this->assertNotEmpty($tail);
        $this->assertNotContains('a', $tail);
        $this->assertFair($tail, ['b' => 3, 'c' => 1]);
    }

    #[Test]
    public function it_labels_the_last_target_standing_as_the_only_choice(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perDay(1))
            ->target('b', 1);

        $distribution->allocate();
        $decision = $distribution->allocate();

        $this->assertSame('b', $decision->target);
        $this->assertSame(Reason::OnlyEligible, $decision->reason);
        $this->assertSame(Status::Saturated, $decision->standings['a']->status);
        $this->assertSame(1, $decision->standings['a']->used);
        $this->assertSame(1, $decision->standings['a']->limit);
        $this->assertStringContainsString('At their cap: a (1/1 until 2023-11-16 00:00:00 UTC).', $decision->explain());
    }

    #[Test]
    public function it_throws_when_every_target_is_at_its_cap(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(1))
            ->target('b', 1, Cap::perHour(1));

        $this->pick($distribution, 2);

        $this->assertFalse($distribution->hasCapacity());

        $this->expectException(NoTargetAvailable::class);
        $this->expectExceptionMessage('Every target is at its cap (a, b); the earliest window resets at 2023-11-15 01:00:00 UTC.');

        $distribution->allocate();
    }

    #[Test]
    public function it_has_capacity_again_after_the_window_rolls(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(1));

        $distribution->allocate();

        $this->assertFalse($distribution->hasCapacity());

        $clock->advance(Cap::HOUR);

        $this->assertTrue($distribution->hasCapacity());
        $this->assertSame('a', $distribution->allocate()->target);
    }

    #[Test]
    public function it_does_not_spend_cap_when_previewing(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(1));

        $distribution->peek();
        $distribution->peek();

        $this->assertSame(0, $distribution->report()->usage('a')->used);
        $this->assertSame('a', $distribution->allocate()->target);
    }

    #[Test]
    public function it_reports_which_targets_are_saturated(): void
    {
        $clock = new FakeClock(FakeClock::START + 10);
        $distribution = WeightedDistribution::make('caps', clock: $clock->closure())
            ->target('a', 1, Cap::perHour(1))
            ->target('b', 1);

        $distribution->allocate();

        $report = $distribution->report();
        $usage = $report->usage('a');

        $this->assertSame('caps', $report->distribution);
        $this->assertSame(1, $report->allocated);
        $this->assertSame(['a'], array_keys($report->saturated()));
        $this->assertSame(['b'], array_keys($report->available()));
        $this->assertTrue($report->isSaturated('a'));
        $this->assertFalse($report->isSaturated('b'));

        $this->assertTrue($usage->saturated);
        $this->assertSame(1, $usage->used);
        $this->assertSame(1, $usage->limit);
        $this->assertSame(0, $usage->remaining());
        $this->assertSame(FakeClock::START + Cap::HOUR, $usage->resetsAt);
        $this->assertSame(1, $usage->allocated);
        $this->assertEqualsWithDelta(0.5, $usage->share, 1e-9);
        $this->assertEqualsWithDelta(1.0, $usage->actual, 1e-9);

        $uncapped = $report->usage('b');

        $this->assertNull($uncapped->limit);
        $this->assertNull($uncapped->used);
        $this->assertNull($uncapped->remaining());
        $this->assertNull($uncapped->resetsAt);
        $this->assertEqualsWithDelta(0.0, $uncapped->actual, 1e-9);
    }

    #[Test]
    public function it_keeps_lifetime_totals_across_windows(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make(clock: $clock->closure())
            ->target('a', 1, Cap::perHour(2))
            ->target('b', 1);

        $this->pick($distribution, 6);
        $clock->advance(Cap::HOUR);
        $this->pick($distribution, 6);

        $report = $distribution->report();

        $this->assertSame(12, $report->allocated);
        $this->assertSame(4, $report->usage('a')->allocated);
        $this->assertSame(8, $report->usage('b')->allocated);
        $this->assertSame(2, $report->usage('a')->used);
    }

    #[Test]
    public function it_builds_caps_from_every_helper(): void
    {
        $this->assertSame(60, Cap::perMinute(5)->seconds);
        $this->assertSame(3600, Cap::perHour(5)->seconds);
        $this->assertSame(86400, Cap::perDay(5)->seconds);
        $this->assertSame(604800, Cap::perWeek(5)->seconds);
        $this->assertSame(90, Cap::per(5, 90)->seconds);
        $this->assertSame(5, Cap::per(5, 90)->limit);
    }

    #[Test]
    public function it_builds_caps_from_their_config_form(): void
    {
        $this->assertEquals(Cap::perDay(100), Cap::fromArray(['limit' => 100, 'per' => 'day']));
        $this->assertEquals(Cap::per(7, 1), Cap::fromArray(['limit' => 7, 'per' => 'second']));
        $this->assertEquals(Cap::per(7, 7200), Cap::fromArray(['limit' => 7, 'per' => 7200]));
    }

    #[Test]
    public function it_rejects_a_zero_limit(): void
    {
        $this->expectException(InvalidCap::class);
        $this->expectExceptionMessage('A cap limit must be at least 1, [0] given.');

        Cap::perDay(0);
    }

    #[Test]
    public function it_rejects_a_zero_length_window(): void
    {
        $this->expectException(InvalidCap::class);
        $this->expectExceptionMessage('A cap window must be at least 1 second, [0] given.');

        Cap::per(5, 0);
    }

    #[Test]
    public function it_rejects_an_unknown_window_unit(): void
    {
        $this->expectException(InvalidCap::class);
        $this->expectExceptionMessage('Unknown cap window [decade].');

        Cap::fromArray(['limit' => 5, 'per' => 'decade']);
    }

    #[Test]
    public function it_rejects_a_malformed_cap_definition(): void
    {
        $this->expectException(InvalidCap::class);
        $this->expectExceptionMessage('A cap definition must be an array with an integer [limit] and a [per] window.');

        Cap::fromArray(['limit' => 'many']);
    }

    #[Test]
    public function it_rejects_a_cap_definition_with_an_unknown_key(): void
    {
        $this->expectException(InvalidCap::class);
        $this->expectExceptionMessage('A cap definition has unknown keys [every]; a cap takes [limit] and [per].');

        Cap::fromArray(['limit' => 10, 'per' => 'day', 'every' => 2]);
    }

    #[Test]
    public function it_floors_windows_before_the_epoch(): void
    {
        $cap = Cap::perMinute(1);

        $this->assertSame(-1, $cap->windowId(-1));
        $this->assertSame(0, $cap->resetsAt(-1));
        $this->assertSame(-1, $cap->windowId(-60));
        $this->assertSame(0, $cap->resetsAt(-60));
        $this->assertSame(-2, $cap->windowId(-61));
        $this->assertSame(-60, $cap->resetsAt(-61));
        $this->assertSame(0, $cap->windowId(0));
        $this->assertSame(60, $cap->resetsAt(0));
    }

    #[Test]
    public function a_window_that_would_end_past_the_last_second_never_ends(): void
    {
        $this->assertSame(PHP_INT_MAX, Cap::perDay(1)->resetsAt(PHP_INT_MAX));
        $this->assertSame(PHP_INT_MAX, Cap::per(1, PHP_INT_MAX)->resetsAt(PHP_INT_MAX - 1));
        $this->assertSame(PHP_INT_MAX - (PHP_INT_MAX % 86400), Cap::perDay(1)->resetsAt(PHP_INT_MAX - (PHP_INT_MAX % 86400) - 1));
    }
}
