<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;

/**
 * Ending a season starts the next; the board starts fresh. A position belongs
 * to the season it was opened in, so calls still open at the turn finish
 * counting toward the season they were made in.
 */
class Seasons
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Locks $locks,
    ) {
    }

    /**
     * End the open season and start the next, under a row lock and the same
     * name as a cache lock (Locks, for SQLite). Busy: refused, so an admin
     * tries again and the monthly roll waits for the next tick.
     *
     * @return array{ended: Season, started: Season}|null null when another run already rolled it
     */
    public function startNew(string $name, ?int $expectCurrent = null): ?array
    {
        return $this->locks->attempt(['season'], fn () => $this->roll($name, $expectCurrent), fn () => throw new Refusal('hype_busy', 429));
    }

    /** @return array{ended: Season, started: Season}|null */
    private function roll(string $name, ?int $expectCurrent): ?array
    {
        return Season::query()->getConnection()->transaction(function () use ($name, $expectCurrent) {
            $current = Season::query()->whereNull('ended_at')->orderByDesc('id')->lockForUpdate()->first() ?? Season::current();
            // The monthly roll names the season it read; if another run already rolled it, stop.
            if ($expectCurrent !== null && $current->id !== $expectCurrent) {
                return null;
            }

            $current->ended_at = Carbon::now();
            $current->save();

            $next = new Season();
            $next->name = $name;
            $next->started_at = Carbon::now();
            $next->save();

            return ['ended' => $current, 'started' => $next];
        });
    }

    /** Monthly mode: the first tick of a new month (UTC) ends last month's season. */
    public function rollMonthly(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        if (! $this->settings->monthlySeasons()) {
            return false;
        }

        $current = Season::current();
        if ($current->started_at->copy()->utc()->format('Y-m') === $now->copy()->utc()->format('Y-m')) {
            return false;
        }

        try {
            return $this->startNew($now->copy()->utc()->format('F Y'), $current->id) !== null;
        } catch (Refusal) {
            // Another run is rolling it right now; the next tick sees the new season.
            return false;
        }
    }
}
