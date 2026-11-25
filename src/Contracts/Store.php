<?php

declare(strict_types=1);

namespace Keaton\WeightedDistribution\Contracts;

use Closure;

interface Store
{
    /**
     * The state saved under the key, or null when nothing has been saved.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * Read the state, hand it to the callback and save what the callback
     * returns. Implementations should make that read-modify-write atomic:
     * two workers must never both start from the same state.
     *
     * @param  Closure(array<string, mixed>|null): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function update(string $key, Closure $callback): array;

    /**
     * Delete the state saved under the key. Implementations should make this
     * wait for any update() in progress on the same key, so an allocation that
     * started before a reset can never write its state back after it.
     */
    public function forget(string $key): void;
}
