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
    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array{ended: Season, started: Season} */
    public function startNew(string $name, ?int $expectCurrent = null): ?array
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

        return $this->startNew($now->copy()->utc()->format('F Y'), $current->id) !== null;
    }
}
