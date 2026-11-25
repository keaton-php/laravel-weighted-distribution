<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

use Closure;
use Keaton\WeightedDistribution\Contracts\Store;
use Keaton\WeightedDistribution\Engine\Outcome;
use Keaton\WeightedDistribution\Engine\Scheduler;
use Keaton\WeightedDistribution\Engine\State;
use Keaton\WeightedDistribution\Exceptions\InvalidConfiguration;
use Keaton\WeightedDistribution\Exceptions\InvalidTarget;
use Keaton\WeightedDistribution\Exceptions\StoreFailure;
use Keaton\WeightedDistribution\Stores\ArrayStore;

/**
 * @phpstan-consistent-constructor
 */
class WeightedDistribution
{
    /** @var array<string, Target> */
    protected array $targets = [];

    protected Store $store;

    protected Closure $clock;

    protected Scheduler $scheduler;

    /**
     * @param  (Closure(): int)|null  $clock  returns the current Unix timestamp
     */
    public function __construct(protected string $name = 'default', ?Store $store = null, ?Closure $clock = null)
    {
        if (trim($name) === '') {
            throw InvalidConfiguration::emptyName();
        }

        $this->store = $store ?? new ArrayStore;
        $this->clock = $clock ?? static fn (): int => time();
        $this->scheduler = new Scheduler;
    }

    /**
     * @param  (Closure(): int)|null  $clock
     */
    public static function make(string $name = 'default', ?Store $store = null, ?Closure $clock = null): static
    {
        return new static($name, $store, $clock);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function target(string $name, int $weight, ?Cap $cap = null): static
    {
        if (isset($this->targets[$name])) {
            throw InvalidTarget::duplicate($name);
        }

        $this->targets[$name] = new Target($name, $weight, $cap);

        return $this;
    }

    /**
     * Register several targets from their config form:
     * ['alpha' => ['weight' => 5, 'cap' => ['limit' => 100, 'per' => 'day']]].
     *
     * @param  array<array-key, mixed>  $definitions
     */
    public function targets(array $definitions): static
    {
        foreach ($definitions as $name => $definition) {
            $weight = is_array($definition) ? ($definition['weight'] ?? null) : null;

            if (! is_array($definition) || ! is_int($weight)) {
                throw InvalidConfiguration::invalidTarget((string) $name);
            }

            if ($unknown = array_diff(array_keys($definition), ['weight', 'cap'])) {
                throw InvalidConfiguration::unknownTargetKeys((string) $name, array_map(strval(...), $unknown));
            }

            $cap = $definition['cap'] ?? null;

            $this->target((string) $name, $weight, match (true) {
                $cap === null => null,
                $cap instanceof Cap => $cap,
                is_array($cap) => Cap::fromArray($cap),
                default => throw InvalidConfiguration::invalidTarget((string) $name),
            });
        }

        return $this;
    }

    /**
     * @param  Closure(): int  $clock
     */
    public function clock(Closure $clock): static
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * Pick the next target and record it. Throws NoTargetAvailable when every
     * target is at its cap (or none is registered).
     */
    public function allocate(): Decision
    {
        $outcome = null;

        $this->store->update($this->name, function (?array $stored) use (&$outcome): array {
            $outcome = $this->scheduler->decide($this->registered(), State::fromArray($stored), $this->now());

            return $outcome->state->toArray();
        });

        if (! $outcome instanceof Outcome) {
            throw StoreFailure::stateNotUpdated($this->name);
        }

        return $outcome->decision;
    }

    /**
     * What allocate() would decide right now, without recording anything.
     */
    public function peek(): Decision
    {
        $state = State::fromArray($this->store->get($this->name));

        return $this->scheduler->decide($this->registered(), $state, $this->now())->decision;
    }

    /**
     * True while at least one target is below its cap.
     */
    public function hasCapacity(): bool
    {
        return $this->report()->available() !== [];
    }

    public function report(): Report
    {
        $state = State::fromArray($this->store->get($this->name));

        return $this->scheduler->report($this->name, $this->registered(), $state, $this->now());
    }

    /**
     * Forget everything the store holds for this distribution: deficits, totals and cap usage.
     */
    public function reset(): static
    {
        $this->store->forget($this->name);

        return $this;
    }

    /**
     * @return list<Target>
     */
    protected function registered(): array
    {
        return array_values($this->targets);
    }

    protected function now(): int
    {
        return ($this->clock)();
    }
}
