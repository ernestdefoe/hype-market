<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;

/**
 * Season totals, rebuilt from settled positions for exactly the members a
 * settlement touched: recounted, never incremented, so a repeat run or a
 * deleted position can't make them drift.
 *
 * 🚨 Under the members' hype_accounts row locks (taken in id order) and the
 * same names as cache locks (Locks, for SQLite): two settlements touching the
 * same member at once (the tick and a moderator's call-off) would otherwise
 * each read the totals before the other's position was written, and the later
 * upsert would drop a settled call. If a lock is busy the rebuild is skipped,
 * and heal() (every tick) recounts whoever it left behind.
 */
class Scores
{
    /** Seasons heal() still checks after they end: positions settle up to a window after the turn. */
    public const HEAL_DAYS = 30;

    public function __construct(private readonly Locks $locks)
    {
    }

    /** @param list<array{0: int, 1: int}> $pairs [seasonId, userId] */
    public function rebuild(array $pairs): void
    {
        $bySeason = [];
        foreach ($pairs as [$seasonId, $userId]) {
            if ($seasonId) {
                $bySeason[$seasonId][$userId] = true;
            }
        }

        if (! $bySeason) {
            return;
        }

        $all = array_values(array_unique(array_merge(...array_map('array_keys', array_values($bySeason)))));
        sort($all);

        $this->locks->attempt(
            array_map(fn (int $id) => 'account.'.$id, $all),
            fn () => Score::query()->getConnection()->transaction(function () use ($bySeason, $all) {
                Account::query()->insertOrIgnore(array_map(fn ($id) => ['user_id' => $id, 'spent' => 0, 'backs' => 0], $all));
                Account::query()->whereIn('user_id', $all)->orderBy('user_id')->lockForUpdate()->get(['user_id']);

                $this->write($bySeason);
            }),
            fn () => null,
        );
    }

    /**
     * Recount every member whose season total doesn't match their settled
     * calls (a rebuild skipped on a busy lock, a crash between settling and
     * recounting, erased positions). Two queries to find them; the tick runs it.
     *
     * @return int how many members were recounted
     */
    public function heal(): int
    {
        $seasons = Season::query()->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', Carbon::now()->subDays(self::HEAL_DAYS)))->pluck('id')->all();
        if (! $seasons) {
            return 0;
        }

        $settled = Position::query()->whereIn('season_id', $seasons)->whereNotNull('settled_at')
            ->groupBy('season_id', 'user_id')->selectRaw('season_id, user_id, COUNT(*) as n')->get()
            ->mapWithKeys(fn (Position $row) => [$row->season_id.':'.$row->user_id => (int) $row->getAttribute('n')]);
        $counted = Score::query()->whereIn('season_id', $seasons)->get(['season_id', 'user_id', 'settled'])
            ->mapWithKeys(fn (Score $s) => [$s->season_id.':'.$s->user_id => $s->settled]);

        $stale = [];
        foreach ($settled->union($counted) as $key => $_) {
            if (($settled[$key] ?? 0) !== ($counted[$key] ?? 0)) {
                [$season, $user] = array_map('intval', explode(':', (string) $key));
                $stale[] = [$season, $user];
            }
        }
        $this->rebuild($stale);

        return count($stale);
    }

    /** @param array<int, array<int, true>> $bySeason */
    private function write(array $bySeason): void
    {
        foreach ($bySeason as $seasonId => $users) {
            $ids = array_keys($users);
            $totals = Position::query()
                ->where('season_id', $seasonId)
                ->whereIn('user_id', $ids)
                ->whereNotNull('settled_at')
                ->groupBy('user_id')
                ->selectRaw('user_id, SUM(profit) as profit, SUM(stake) as staked, COUNT(*) as settled, SUM(CASE WHEN profit > 0 THEN 1 ELSE 0 END) as wins, MAX(multiplier) as best')
                ->get()
                ->keyBy('user_id');

            $rows = [];
            foreach ($ids as $userId) {
                $row = $totals->get($userId);
                $rows[] = [
                    'season_id' => $seasonId,
                    'user_id' => $userId,
                    'profit' => (int) ($row->profit ?? 0),
                    'staked' => (int) ($row->staked ?? 0),
                    'settled' => (int) ($row->settled ?? 0),
                    'wins' => (int) ($row->wins ?? 0),
                    'best_multiplier' => (float) ($row->best ?? 0),
                ];
            }

            Score::query()->upsert($rows, ['season_id', 'user_id'], ['profit', 'staked', 'settled', 'wins', 'best_multiplier']);
        }
    }
}
