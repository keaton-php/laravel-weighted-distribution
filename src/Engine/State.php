<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Engine;

use Keaton\WeightedDistribution\Exceptions\StoreFailure;

/**
 * What a store keeps for one distribution. Plain scalars and arrays only, so
 * any cache, database column or file can hold it. A field of the wrong type is
 * ignored (read as never saved); a negative number, or totals too large to
 * count one more allocation, is refused as invalid.
 *
 * @internal
 */
final readonly class State
{
    /**
     * @param  string  $signature  fingerprint of the targets the current cycle was run for
     * @param  int  $position  slots already served in the current cycle
     * @param  array<string, int>  $counts  picks per target in the current cycle
     * @param  array<string, int>  $totals  picks per target over the whole lifetime
     * @param  array<string, array{window: int, used: int}>  $usage  cap usage per capped target
     */
    public function __construct(
        public string $signature = '',
        public int $position = 0,
        public array $counts = [],
        public array $totals = [],
        public array $usage = [],
    ) {
        //
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self;
        }

        $signature = $data['signature'] ?? '';
        $position = $data['position'] ?? 0;
        $totals = self::counts($data['totals'] ?? null);

        if (is_int($position) && $position < 0) {
            throw StoreFailure::invalidState('a negative cycle position');
        }

        // One more allocation must still fit in an int, as Decision::$sequence and Report::$allocated.
        $sum = 0;

        foreach ($totals as $total) {
            if ($total > PHP_INT_MAX - 1 - $sum) {
                throw StoreFailure::invalidState('the lifetime totals are too large to count');
            }

            $sum += $total;
        }

        return new self(
            is_string($signature) ? $signature : '',
            is_int($position) ? $position : 0,
            self::counts($data['counts'] ?? null),
            $totals,
            self::usage($data['usage'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'signature' => $this->signature,
            'position' => $this->position,
            'counts' => $this->counts,
            'totals' => $this->totals,
            'usage' => $this->usage,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected static function counts(mixed $value): array
    {
        $counts = [];

        foreach (is_array($value) ? $value : [] as $name => $count) {
            if (is_int($count) && $count < 0) {
                throw StoreFailure::invalidState("a negative count for [{$name}]");
            }

            if (is_int($count)) {
                $counts[(string) $name] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, array{window: int, used: int}>
     */
    protected static function usage(mixed $value): array
    {
        $usage = [];

        foreach (is_array($value) ? $value : [] as $name => $entry) {
            if (is_array($entry) && is_int($entry['used'] ?? null) && $entry['used'] < 0) {
                throw StoreFailure::invalidState("negative cap usage for [{$name}]");
            }

            if (is_array($entry) && is_int($entry['window'] ?? null) && is_int($entry['used'] ?? null)) {
                $usage[(string) $name] = ['window' => $entry['window'], 'used' => $entry['used']];
            }
        }

        return $usage;
    }
}
