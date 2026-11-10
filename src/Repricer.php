<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Moves open markets' prices (the tick, and every back). Two queries for the
 * engagement and one for the positions, however many markets.
 *
 * Every backer's own engagement is left out of the price for everyone, so
 * backing and then replying (or getting a fellow backer to) moves nothing. A
 * position's entry price is read again with the CURRENT backers left out, so a
 * later backer's earlier replies never shift an earlier backer's numbers.
 */
class Repricer
{
    /** Sparkline points: one an hour (longer windows space them out). */
    public const STEP = 3600;

    public function __construct(private readonly Engagement $engagement)
    {
    }

    /** @param Collection<int, Market> $markets */
    public function reprice(Collection $markets, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();
        $markets = $markets->filter(fn (Market $m) => ! $m->settled_at)->values();
        if ($markets->isEmpty()) {
            return;
        }

        $events = $this->engagement->load($markets);
        $positions = Position::query()->whereIn('market_id', $markets->pluck('id')->all())->get()->groupBy('market_id');

        foreach ($markets as $market) {
            /** @var Collection<int, Position> $held */
            $held = $positions->get($market->id, new Collection());
            $excluded = self::excluded($market, $held->pluck('user_id')->all());
            $list = $events[$market->id] ?? [];
            $until = min($now->getTimestamp(), $market->settles_at->getTimestamp());
            $result = Pricing::at($list, $until, $excluded);

            $history = self::history($market, $list, $until, $excluded);

            $changed = abs($result['price'] - $market->price) > 0.001 || $result['participants'] !== $market->participants
                || $held->count() !== $market->backers_count || (int) $held->sum('stake') !== $market->staked
                || count($history) !== count($market->history ?? []);

            // Only markets that moved are written. Conditional: never over one that settled since it was read.
            if ($changed) {
                Market::query()->whereKey($market->id)->whereNull('settled_at')->update([
                    'price' => $result['price'],
                    'participants' => $result['participants'],
                    'backers_count' => $held->count(),
                    'staked' => (int) $held->sum('stake'),
                    'history' => json_encode($history),
                    'priced_at' => $now,
                ]);
            }
            $market->price = $result['price'];
            $market->participants = $result['participants'];
            $market->backers_count = $held->count();
            $market->staked = (int) $held->sum('stake');
            $market->history = $history;

            foreach ($held as $position) {
                $entry = Pricing::at($list, $position->created_at->getTimestamp(), $excluded)['price'];
                if (abs($entry - $position->entry_price) > 0.001) {
                    Position::query()->whereKey($position->id)->whereNull('settled_at')->update(['entry_price' => $entry]);
                }
            }
        }
    }

    /**
     * The author and every backer.
     *
     * @param list<int> $backers
     * @return array<int, true>
     */
    public static function excluded(Market $market, array $backers): array
    {
        $excluded = array_fill_keys(array_map('intval', $backers), true);
        if ($market->user_id) {
            $excluded[(int) $market->user_id] = true;
        }

        return $excluded;
    }

    /**
     * The sparkline: the price at every hour since the market opened, re-read
     * from the events each time, so it follows hidden posts and new backers'
     * exclusions as the live price does. At most ~100 points for any window.
     *
     * @param list<array{t: int, u: int, k: string}> $events
     * @param array<int, true> $excluded
     * @return list<array{0: int, 1: float}>
     */
    public static function history(Market $market, array $events, int $until, array $excluded): array
    {
        $from = $market->opened_at->getTimestamp();
        $step = max(self::STEP, (int) ceil(($market->settles_at->getTimestamp() - $from) / 96 / self::STEP) * self::STEP);
        $points = [];
        for ($at = $from; $at < $until; $at += $step) {
            $points[] = [$at, Pricing::at($events, $at, $excluded)['price']];
        }
        $points[] = [$until, Pricing::at($events, $until, $excluded)['price']];

        return $points;
    }
}
