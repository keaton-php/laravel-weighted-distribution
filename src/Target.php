<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution;

use Keaton\WeightedDistribution\Exceptions\InvalidTarget;

final readonly class Target
{
    public function __construct(
        public string $name,
        public int $weight,
        public ?Cap $cap = null,
    ) {
        if (trim($name) === '') {
            throw InvalidTarget::emptyName();
        }

        // The name is part of the saved state, which is JSON.
        if (preg_match('//u', $name) !== 1) {
            throw InvalidTarget::invalidUtf8();
        }

        if ($weight < 1) {
            throw InvalidTarget::weightMustBePositive($name, $weight);
        }
    }
}
