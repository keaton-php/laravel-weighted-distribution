<?php

declare(strict_types=1);

/*
 * Classes the documentation pages use without printing in full: a file-backed Store the
 * "Stores" page writes against, and the lead router the "In a Laravel app" page routes with.
 * The docs tests (tests/Feature/DocsExamplesTest.php) load this file before running the page
 * examples. They live in the global namespace because the pages use them that way.
 */

use Keaton\WeightedDistribution\Cap;
use Keaton\WeightedDistribution\Contracts\Store;
use Keaton\WeightedDistribution\Report;
use Keaton\WeightedDistribution\WeightedDistribution;

/**
 * Keeps each distribution's state in a JSON file, guarded by a file lock: a shared lock for
 * reads and an exclusive one for update() and forget(). It throws rather than carry on when the
 * file cannot be locked, read or written, or does not hold the JSON object it saved.
 */
class FileStore implements Store
{
    public function __construct(protected string $directory)
    {
        //
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->locked($key, LOCK_SH, fn ($handle): ?array => $this->read($handle, $key));
    }

    /**
     * @param  Closure(array<string, mixed>|null): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function update(string $key, Closure $callback): array
    {
        return $this->locked($key, LOCK_EX, function ($handle) use ($key, $callback): array {
            $state = $callback($this->read($handle, $key));

            $this->write($handle, $key, json_encode($state, JSON_THROW_ON_ERROR));

            return $state;
        });
    }

    // Empties the file under the same lock as update(), so a reset cannot race an allocation.
    public function forget(string $key): void
    {
        $this->locked($key, LOCK_EX, fn ($handle): null => $this->write($handle, $key, ''));
    }

    /**
     * @template T
     *
     * @param  int<0, 7>  $mode
     * @param  Closure(resource): T  $callback
     * @return T
     */
    protected function locked(string $key, int $mode, Closure $callback): mixed
    {
        $handle = fopen($this->path($key), 'c+');

        if ($handle === false || ! flock($handle, $mode)) {
            throw new RuntimeException("Could not lock the state file for [{$key}].");
        }

        try {
            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * An empty file is no state; anything else must be the JSON object that write() saved.
     *
     * @param  resource  $handle
     * @return array<string, mixed>|null
     */
    protected function read($handle, string $key): ?array
    {
        if (($json = stream_get_contents($handle)) === false) {
            throw new RuntimeException("Could not read the state file for [{$key}].");
        }

        if ($json === '') {
            return null;
        }

        if (! json_decode($json, flags: JSON_THROW_ON_ERROR) instanceof stdClass) {
            throw new RuntimeException("The state file for [{$key}] does not hold a JSON object.");
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param resource $handle */
    protected function write($handle, string $key, string $json): null
    {
        if (! ftruncate($handle, 0) || ! rewind($handle) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
            throw new RuntimeException("Could not save the state file for [{$key}].");
        }

        return null;
    }

    protected function path(string $key): string
    {
        return $this->directory.'/'.sha1($key).'.json';
    }
}

/**
 * Routes each lead to a sales team by weight, with daily caps, and returns the reason with it,
 * for the app to save alongside the lead.
 */
class LeadRouter
{
    protected WeightedDistribution $teams;

    public function __construct(Store $store, ?Closure $clock = null)
    {
        $this->teams = WeightedDistribution::make('sales-teams', $store, $clock)
            ->target('north', 5, Cap::perDay(120))
            ->target('south', 3)
            ->target('west', 2, Cap::perDay(40));
    }

    /**
     * Pick a team for the lead, and return the reason with it, to save with the lead.
     *
     * @return array{lead: int, team: string, why: string}
     */
    public function route(int $lead): array
    {
        $decision = $this->teams->allocate();

        return ['lead' => $lead, 'team' => $decision->target, 'why' => $decision->explain()];
    }

    public function report(): Report
    {
        return $this->teams->report();
    }
}
