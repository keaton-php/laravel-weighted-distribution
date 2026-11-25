<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Tests\Unit;

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\Exceptions\InvalidTarget;
use Keaton\WeightedDistribution\Reason;
use Keaton\WeightedDistribution\Status;
use Keaton\WeightedDistribution\Stores\ArrayStore;
use Keaton\WeightedDistribution\Tests\Concerns\MeasuresFairness;
use Keaton\WeightedDistribution\Tests\Support\FakeClock;
use Keaton\WeightedDistribution\Tests\Support\RecordingStore;
use Keaton\WeightedDistribution\WeightedDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AllocationTest extends TestCase
{
    use MeasuresFairness;

    #[Test]
    public function it_serves_two_to_one_as_a_b_a(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 2)->target('b', 1);

        $this->assertSame(['a', 'b', 'a', 'a', 'b', 'a'], $this->pick($distribution, 6));
    }

    #[Test]
    public function it_rotates_equal_weights_in_registration_order(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 1)->target('b', 1)->target('c', 1);

        $this->assertSame(['a', 'b', 'c', 'a', 'b', 'c'], $this->pick($distribution, 6));
    }

    #[Test]
    public function it_spreads_a_heavy_target_between_the_light_ones(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 5)->target('b', 1)->target('c', 1);

        $this->assertSame(
            ['a', 'a', 'b', 'a', 'a', 'c', 'a', 'a', 'a', 'b', 'a', 'a', 'c', 'a'],
            $this->pick($distribution, 14),
        );
    }

    #[Test]
    public function it_gives_the_same_sequence_to_weights_that_share_a_factor(): void
    {
        $reduced = WeightedDistribution::make()->target('a', 2)->target('b', 1);
        $scaled = WeightedDistribution::make()->target('a', 400)->target('b', 200);

        $this->assertSame($this->pick($reduced, 9), $this->pick($scaled, 9));
        $this->assertSame(3, $scaled->reset()->allocate()->cycle);
    }

    #[Test]
    public function it_produces_exactly_what_the_readme_shows(): void
    {
        $clock = new FakeClock;
        $leads = WeightedDistribution::make('leads', clock: $clock->closure())
            ->target('alpha', 5)
            ->target('beta', 3, Cap::perDay(100))
            ->target('gamma', 2);

        $first = $leads->allocate();

        $this->assertSame(
            "Allocation #1: 'alpha' was chosen. It was owed 0.50 of an allocation (slot 1 of 10 in this cycle) and had the earliest deadline of the 2 targets due one. Not due one yet: gamma.",
            $first->explain(),
        );

        $rest = $this->pick($leads, 9);

        $this->assertSame(
            'alpha beta alpha gamma alpha beta alpha gamma beta alpha',
            implode(' ', [$first->target, ...$rest]),
        );
    }

    #[Test]
    public function it_explains_the_first_decision(): void
    {
        $clock = new FakeClock;
        $distribution = WeightedDistribution::make('leads', clock: $clock->closure())
            ->target('a', 5)
            ->target('b', 1)
            ->target('c', 1);

        $decision = $distribution->allocate();

        $this->assertSame('a', $decision->target);
        $this->assertSame(Reason::Deficit, $decision->reason);
        $this->assertSame(1, $decision->sequence);
        $this->assertSame(1, $decision->position);
        $this->assertSame(7, $decision->cycle);
        $this->assertSame(FakeClock::START, $decision->at);

        $this->assertSame(Status::Chosen, $decision->standings['a']->status);
        $this->assertSame(Status::Waiting, $decision->standings['b']->status);
        $this->assertSame(Status::Waiting, $decision->standings['c']->status);

        $this->assertEqualsWithDelta(5 / 7, $decision->standing()->owed, 1e-9);
        $this->assertEqualsWithDelta(5 / 7, $decision->standing()->share, 1e-9);
        $this->assertSame(0, $decision->standing()->allocated);

        $explanation = $decision->explain();

        $this->assertStringContainsString("Allocation #1: 'a' was chosen", $explanation);
        $this->assertStringContainsString('was the only target due one', $explanation);
        $this->assertStringContainsString('Not due one yet: b, c.', $explanation);
    }

    #[Test]
    public function it_marks_other_targets_that_were_due_as_candidates(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 5)->target('b', 1)->target('c', 1);

        $distribution->allocate();
        $second = $distribution->allocate();

        $this->assertSame('a', $second->target);
        $this->assertSame(Status::Candidate, $second->standings['b']->status);
        $this->assertSame(Status::Candidate, $second->standings['c']->status);
        $this->assertSame(1, $second->standing()->allocated);
        $this->assertStringContainsString('had the earliest deadline of the 3 targets due one', $second->explain());
    }

    #[Test]
    public function it_labels_a_lone_target_as_the_only_choice(): void
    {
        $distribution = WeightedDistribution::make()->target('solo', 3);

        $first = $distribution->allocate();
        $second = $distribution->allocate();

        $this->assertSame('solo', $first->target);
        $this->assertSame(Reason::OnlyEligible, $first->reason);
        $this->assertSame(1, $first->cycle);
        $this->assertSame(2, $second->sequence);
        $this->assertStringContainsString('only target with capacity left', $second->explain());
    }

    #[Test]
    public function it_counts_the_position_inside_each_cycle(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 1)->target('b', 1)->target('c', 1);

        $positions = [];

        for ($i = 0; $i < 4; $i++) {
            $decision = $distribution->allocate();
            $positions[] = [$decision->sequence, $decision->position, $decision->cycle];
        }

        $this->assertSame([[1, 1, 3], [2, 2, 3], [3, 3, 3], [4, 1, 3]], $positions);
    }

    #[Test]
    public function it_exposes_the_decision_as_an_array_for_logging(): void
    {
        $decision = WeightedDistribution::make()->target('a', 1)->target('b', 1)->allocate();

        $array = $decision->toArray();

        $this->assertSame(['target', 'reason', 'sequence', 'position', 'cycle', 'at', 'standings'], array_keys($array));
        $this->assertSame('a', $array['target']);
        $this->assertSame('deficit', $array['reason']);
        $this->assertSame(['a', 'b'], array_column($array['standings'], 'target'));
        $this->assertSame(['chosen', 'candidate'], array_column($array['standings'], 'status'));
    }

    #[Test]
    public function it_previews_the_next_pick_without_recording_it(): void
    {
        $store = new RecordingStore;
        $distribution = WeightedDistribution::make('preview', $store)->target('a', 2)->target('b', 1);

        $this->assertSame('a', $distribution->allocate()->target);

        $first = $distribution->peek();
        $second = $distribution->peek();

        $this->assertSame('b', $first->target);
        $this->assertSame('b', $second->target);
        $this->assertSame(1, $store->updates);
        $this->assertSame('b', $distribution->allocate()->target);
    }

    #[Test]
    public function it_starts_over_after_a_reset(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 2)->target('b', 1);

        $this->assertSame(['a', 'b'], $this->pick($distribution, 2));

        $distribution->reset();

        $this->assertSame(0, $distribution->report()->allocated);
        $this->assertSame(['a', 'b', 'a'], $this->pick($distribution, 3));
    }

    #[Test]
    public function it_restarts_the_cycle_when_the_target_set_changes(): void
    {
        $distribution = WeightedDistribution::make()->target('a', 1)->target('b', 1);

        $this->assertSame('a', $distribution->allocate()->target);

        $distribution->target('c', 1);
        $next = $distribution->allocate();

        $this->assertSame('a', $next->target);
        $this->assertSame(1, $next->position);
        $this->assertSame(2, $next->sequence);
    }

    #[Test]
    public function it_continues_the_sequence_from_a_shared_store(): void
    {
        $store = new ArrayStore;

        $first = WeightedDistribution::make('leads', $store)->target('a', 5)->target('b', 3)->target('c', 2);
        $second = WeightedDistribution::make('leads', $store)->target('a', 5)->target('b', 3)->target('c', 2);

        $interleaved = [];

        for ($i = 0; $i < 10; $i++) {
            $interleaved[] = ($i % 2 === 0 ? $first : $second)->allocate()->target;
        }

        $alone = $this->pick(WeightedDistribution::make('leads')->target('a', 5)->target('b', 3)->target('c', 2), 10);

        $this->assertSame($alone, $interleaved);
        $this->assertFair($interleaved, ['a' => 5, 'b' => 3, 'c' => 2]);
    }

    #[Test]
    public function it_keeps_distributions_with_different_names_apart(): void
    {
        $store = new ArrayStore;

        $x = WeightedDistribution::make('x', $store)->target('a', 1)->target('b', 1);
        $y = WeightedDistribution::make('y', $store)->target('a', 1)->target('b', 1);

        $this->assertSame(['a', 'b'], $this->pick($x, 2));
        $this->assertSame(['a', 'b'], $this->pick($y, 2));
        $this->assertSame(2, $x->report()->allocated);
    }

    #[Test]
    public function it_reports_the_name_it_was_given(): void
    {
        $this->assertSame('default', WeightedDistribution::make()->name());
        $this->assertSame('leads', WeightedDistribution::make('leads')->name());
    }

    #[Test]
    public function it_calls_a_target_that_is_behind_but_not_yet_due_waiting_not_ahead(): void
    {
        $decision = WeightedDistribution::make()
            ->target('alpha', 5)
            ->target('beta', 3)
            ->target('gamma', 2)
            ->peek();

        // gamma has had nothing and is owed 0.2, below the 0.25 a third target needs.
        $gamma = $decision->standings['gamma'];

        $this->assertSame(0, $gamma->allocated);
        $this->assertEqualsWithDelta(0.2, $gamma->owed, 1e-9);
        $this->assertSame(Status::Waiting, $gamma->status);
        $this->assertStringEndsWith('Not due one yet: gamma.', $decision->explain());
    }

    #[Test]
    public function it_counts_allocations_to_a_target_that_is_no_longer_registered(): void
    {
        $store = new ArrayStore;

        $before = WeightedDistribution::make('leads', $store)->target('a', 1)->target('b', 1);
        $before->allocate();
        $before->allocate();

        $after = WeightedDistribution::make('leads', $store)->target('a', 1);

        $this->assertSame(2, $after->report()->allocated);
        $this->assertSame(0.5, $after->report()->usage('a')->actual);
        $this->assertSame(3, $after->allocate()->sequence);
    }

    #[Test]
    public function it_schedules_the_largest_weights_it_accepts_exactly(): void
    {
        // Two targets: 2 * total * total must fit in an int, so a total of 2,000,000,001 does.
        $decision = WeightedDistribution::make()->target('big', 2_000_000_000)->target('small', 1)->allocate();

        $this->assertSame('big', $decision->target);
        $this->assertSame(2_000_000_001, $decision->cycle);
    }

    #[Test]
    public function it_refuses_weights_too_large_to_compare_exactly(): void
    {
        $distribution = WeightedDistribution::make()->target('big', 3_000_000_000)->target('small', 1);

        $this->expectException(InvalidTarget::class);
        $this->expectExceptionMessage('The weights are too large to schedule with exact integer arithmetic');

        $distribution->allocate();
    }

    #[Test]
    public function it_refuses_weights_whose_sum_overflows(): void
    {
        $distribution = WeightedDistribution::make()->target('a', PHP_INT_MAX)->target('b', PHP_INT_MAX - 1);

        $this->expectException(InvalidTarget::class);

        $distribution->peek();
    }

    #[Test]
    public function numeric_names_log_as_the_same_json_shape_as_any_others(): void
    {
        $decision = WeightedDistribution::make()->target('0', 1)->target('1', 1)->allocate();

        $this->assertSame('0', $decision->target);
        $this->assertSame('0', $decision->standing()->target);
        $this->assertSame(['0', '1'], array_column($decision->toArray()['standings'], 'target'));
        $this->assertStringStartsWith('[{"target":"0"', (string) json_encode($decision->toArray()['standings']));

        $named = WeightedDistribution::make()->target('north', 1)->target('south', 1)->allocate();
        $this->assertStringStartsWith('[{"target":"north"', (string) json_encode($named->toArray()['standings']));
    }
}
