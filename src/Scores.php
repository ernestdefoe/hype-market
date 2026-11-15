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
    /** Most settled positions one heal() reads; the rest wait for the next tick. */
    public static int $healChunk = 500;

    public function __construct(
        private readonly Locks $locks,
        private readonly Settings $settings,
    ) {
    }

    /** @param list<array{0: int, 1: int}> $pairs [seasonId, userId] */
    /** @return bool false when a member's lock was busy and nothing was written (heal() tries again) */
    public function rebuild(array $pairs): bool
    {
        $bySeason = [];
        foreach ($pairs as [$seasonId, $userId]) {
            if ($seasonId) {
                $bySeason[$seasonId][$userId] = true;
            }
        }

        if (! $bySeason) {
            return true;
        }

        $all = array_values(array_unique(array_merge(...array_map('array_keys', array_values($bySeason)))));
        sort($all);

        return $this->locks->attempt(
            array_map(fn (int $id) => 'account.'.$id, $all),
            fn () => Score::query()->getConnection()->transaction(function () use ($bySeason, $all) {
                Account::query()->insertOrIgnore(array_map(fn ($id) => ['user_id' => $id, 'spent' => 0, 'backs' => 0], $all));
                Account::query()->whereIn('user_id', $all)->orderBy('user_id')->lockForUpdate()->get(['user_id']);

                $this->write($bySeason);

                return true;
            }),
            fn () => false,
        );
    }

    /**
     * Recount the members whose calls settled since the last heal: whoever a
     * busy lock or a crash between settling and recounting left behind. A
     * cursor (settled_at, id) moves forward only once their recount is
     * written, and each run reads at most HEAL_CHUNK positions, so the work
     * follows new settlements, never the size of the forum. The tick runs it.
     *
     * @return int how many members were recounted
     */
    public function heal(): int
    {
        [$at, $id] = $this->settings->healCursor();

        $rows = Position::query()
            ->whereNotNull('settled_at')
            // A minute behind: every settlement written in a given second has finished by then, so the
            // cursor never passes a position a concurrent call-off was still writing.
            ->where('settled_at', '<=', Carbon::now()->subMinute())
            ->where(fn ($q) => $q->where('settled_at', '>', $at)->orWhere(fn ($q) => $q->where('settled_at', $at)->where('id', '>', $id)))
            ->orderBy('settled_at')->orderBy('id')
            ->limit(self::$healChunk)
            ->get(['id', 'season_id', 'user_id', 'settled_at']);
        if ($rows->isEmpty()) {
            return 0;
        }

        $pairs = [];
        foreach ($rows as $row) {
            $pairs[$row->season_id.':'.$row->user_id] = [(int) $row->season_id, (int) $row->user_id];
        }
        if (! $this->rebuild(array_values($pairs))) {
            return 0;
        }

        /** @var Position $last */
        $last = $rows->last();
        $this->settings->moveHealCursor($last->settled_at, (int) $last->id);

        return count($pairs);
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
