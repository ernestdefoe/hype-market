<?php

namespace ErnestDefoe\HypeMarket;

/**
 * Season totals, rebuilt from settled positions for exactly the members a
 * settlement touched: recounted, never incremented, so a repeat run or a
 * deleted position can't make them drift. One query to read, one to write.
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
