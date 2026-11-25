<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

use Keaton\WeightedDistribution\Exceptions\InvalidCap;

final readonly class Cap
{
    public const MINUTE = 60;

    public const HOUR = 3600;

    public const DAY = 86400;

    public const WEEK = 604800;

    public function __construct(
        public int $limit,
        public int $seconds,
    ) {
        if ($limit < 1) {
            throw InvalidCap::limitMustBePositive($limit);
        }

        if ($seconds < 1) {
            throw InvalidCap::windowMustBePositive($seconds);
        }
    }

    public static function per(int $limit, int $seconds): self
    {
        return new self($limit, $seconds);
    }

    public static function perMinute(int $limit): self
    {
        return new self($limit, self::MINUTE);
    }

    public static function perHour(int $limit): self
    {
        return new self($limit, self::HOUR);
    }

    public static function perDay(int $limit): self
    {
        return new self($limit, self::DAY);
    }

    public static function perWeek(int $limit): self
    {
        return new self($limit, self::WEEK);
    }

    /**
     * Build a cap from its config form: ['limit' => 100, 'per' => 'day'].
     *
     * @param  array<array-key, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $limit = $definition['limit'] ?? null;
        $per = $definition['per'] ?? null;

        if (! is_int($limit) || (! is_int($per) && ! is_string($per))) {
            throw InvalidCap::malformed();
        }

        if ($unknown = array_diff(array_keys($definition), ['limit', 'per'])) {
            throw InvalidCap::unknownKeys(array_map(strval(...), $unknown));
        }

        $seconds = match ($per) {
            'second' => 1,
            'minute' => self::MINUTE,
            'hour' => self::HOUR,
            'day' => self::DAY,
            'week' => self::WEEK,
            default => is_int($per) ? $per : throw InvalidCap::unknownUnit($per),
        };

        return new self($limit, $seconds);
    }

    // Windows are fixed and aligned to the Unix epoch (a day window rolls at 00:00 UTC).
    // Floor division, so a timestamp before the epoch falls in the window that ends at or before 0.
    public function windowId(int $now): int
    {
        return intdiv($now, $this->seconds) - ($now % $this->seconds < 0 ? 1 : 0);
    }

    // The start of the next window; a window that would end past the last representable second never ends.
    public function resetsAt(int $now): int
    {
        $window = $this->windowId($now);

        if ($window >= intdiv(PHP_INT_MAX, $this->seconds)) {
            return PHP_INT_MAX;
        }

        return ($window + 1) * $this->seconds;
    }
}
