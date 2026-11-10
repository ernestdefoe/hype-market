<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use ErnestDefoe\HypeMarket\Notification\HypeFlatBlueprint;
use ErnestDefoe\HypeMarket\Notification\HypePaidBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * Settling a market: server-side only (the tick, or a moderator calling it off).
 *
 * 🚨 Idempotent and race-safe: the market row is locked and `settled_at` is
 * checked inside the transaction, so of two runs that overlap exactly one
 * settles and the other does nothing. Prices come from Pricing over the
 * stored engagement up to the settle time, so a late run lands on the same
 * numbers as an on-time one. Season totals are rebuilt from positions after
 * the commit, never incremented.
 *
 * Per position, on top of the market's exclusions (author + every backer):
 * a member who already lifted PAIR_CAP of this backer's paid calls this
 * season no longer counts toward their calls. Friends taking turns lifting
 * each other's picks is the farming this stops.
 */
class Settler
{
    public const PAIR_CAP = 5;

    public function __construct(
        private readonly Engagement $engagement,
        private readonly Scores $scores,
        private readonly Settings $settings,
        private readonly NotificationSyncer $notifications,
    ) {
    }

    /** @return bool whether this call settled it */
    public function settle(int $marketId, bool $void = false): bool
    {
        $result = Market::query()->getConnection()->transaction(function () use ($marketId, $void) {
            $now = Carbon::now();
            /** @var Market|null $market */
            $market = Market::query()->whereKey($marketId)->lockForUpdate()->first();
            if (! $market || $market->settled_at || (! $void && $now->lt($market->settles_at))) {
                return null;
            }

            $discussion = $market->discussion()->first();
            // Gone, hidden or made private before it settled: called off, every stake back flat.
            $void = $void || ! $discussion || $discussion->hidden_at || $discussion->is_private;

            /** @var Collection<int, Position> $positions */
            $positions = $market->positions()->orderBy('created_at')->orderBy('id')->get();
            $settleAt = $market->settles_at->getTimestamp();
            $final = ['price' => $market->price, 'participants' => $market->participants];

            if ($void) {
                foreach ($positions as $position) {
                    $this->write($position, $position->entry_price, 1.0, [], $now);
                }
            } else {
                $events = $this->engagement->load(new Collection([$market]))[$market->id] ?? [];
                $excluded = Repricer::excluded($market, $positions->pluck('user_id')->all());
                $final = Pricing::at($events, $settleAt, $excluded);
                $capped = $this->capped($positions, $market->id);
                $min = $this->settings->minParticipants();

                foreach ($positions as $position) {
                    $mine = $excluded + array_fill_keys($capped[$position->user_id.':'.$position->season_id] ?? [], true);
                    $entryAt = $position->created_at->getTimestamp();
                    $entry = Pricing::at($events, $entryAt, $mine)['price'];
                    $exit = Pricing::at($events, $settleAt, $mine, $entryAt);
                    $multiplier = Pricing::multiplier($entry, $exit['price'], $exit['participants'], $min, $position->loss);
                    $position->exit_price = $exit['price'];
                    $this->write($position, $entry, $multiplier, $multiplier > 1 ? $exit['contributors'] : [], $now);
                }
            }

            $market->price = $final['price'];
            $market->final_price = $final['price'];
            $market->participants = $final['participants'];
            $market->settled_at = $now;
            $market->voided_at = $void ? $now : null;
            $market->save();

            return [$market, $positions, $void];
        });

        if (! $result) {
            return false;
        }

        [$market, $positions, $void] = $result;
        $this->scores->rebuild($positions->map(fn (Position $p) => [(int) $p->season_id, (int) $p->user_id])->all());
        $this->notify($market, $positions, $void);

        return true;
    }

    private function write(Position $position, float $entry, float $multiplier, array $contributors, Carbon $now): void
    {
        $position->entry_price = $entry;
        $position->exit_price ??= $entry;
        $position->multiplier = $multiplier;
        $position->payout = Pricing::payout($position->stake, $multiplier);
        $position->profit = $position->payout - $position->stake;
        $position->contributors = array_values($contributors);
        $position->settled_at = $now;
        $position->save();
    }

    /**
     * Members who already lifted PAIR_CAP of each backer's paid calls in that
     * season, keyed "user:season". One query for all of a market's backers.
     *
     * @param Collection<int, Position> $positions
     * @return array<string, list<int>>
     */
    private function capped(Collection $positions, int $marketId): array
    {
        if ($positions->isEmpty()) {
            return [];
        }

        $earlier = Position::query()
            ->whereIn('user_id', $positions->pluck('user_id')->unique()->values()->all())
            ->whereIn('season_id', $positions->pluck('season_id')->filter()->unique()->values()->all() ?: [0])
            ->where('market_id', '<>', $marketId)
            ->whereNotNull('settled_at')
            ->where('profit', '>', 0)
            ->get(['user_id', 'season_id', 'contributors']);

        $counts = [];
        foreach ($earlier as $position) {
            foreach ($position->contributors ?? [] as $memberId) {
                $key = $position->user_id.':'.$position->season_id;
                $counts[$key][(int) $memberId] = ($counts[$key][(int) $memberId] ?? 0) + 1;
            }
        }

        $capped = [];
        foreach ($counts as $key => $members) {
            $capped[$key] = array_keys(array_filter($members, fn (int $n) => $n >= self::PAIR_CAP));
        }

        return $capped;
    }

    /**
     * "Your call paid off" or "settled flat". Blueprints carry no data, so a
     * repeat run's sync() matches the alert it already sent.
     *
     * @param Collection<int, Position> $positions
     */
    private function notify(Market $market, Collection $positions, bool $void): void
    {
        $users = User::query()->whereIn('id', $positions->pluck('user_id')->all() ?: [0])->get()->keyBy('id');
        $paid = [];
        $flat = [];
        foreach ($positions as $position) {
            $user = $users->get($position->user_id);
            if ($user) {
                ! $void && $position->profit > 0 ? $paid[] = $user : $flat[] = $user;
            }
        }

        $this->notifications->sync(new HypePaidBlueprint($market), $paid);
        $this->notifications->sync(new HypeFlatBlueprint($market), $flat);
    }
}
