<?php

namespace ErnestDefoe\HypeMarket;

/**
 * Season totals, rebuilt from settled positions for exactly the members a
 * settlement touched: recounted, never incremented, so a repeat run or a
 * deleted position can't make them drift.
 *
 * 🚨 Under the members' hype_accounts row locks (taken in id order): two
 * settlements touching the same member at once (the tick and a moderator's
 * call-off) would otherwise each read the totals before the other's position
 * was written, and the later upsert would drop a settled call.
 */
class Scores
{
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

        Score::query()->getConnection()->transaction(function () use ($bySeason, $all) {
            Account::query()->insertOrIgnore(array_map(fn ($id) => ['user_id' => $id, 'spent' => 0, 'backs' => 0], $all));
            Account::query()->whereIn('user_id', $all)->orderBy('user_id')->lockForUpdate()->get(['user_id']);

            $this->write($bySeason);
        });
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
