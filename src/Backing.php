<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * Backing a discussion.
 *
 * 🚨 Race-safe: the market row and then the member's account row are locked
 * (SELECT … FOR UPDATE) and everything is judged again inside the transaction:
 * the closing time, the daily allowance and back count, and "already backed".
 * Two backs fired at once can't both pass a cap, a back can't land after the
 * market closed or settled, and one position per member per market is a
 * unique key as well. The entry price is always the server's, never sent in.
 */
class Backing
{
    public function __construct(
        private readonly Markets $markets,
        private readonly Engagement $engagement,
        private readonly Repricer $repricer,
        private readonly Settings $settings,
    ) {
    }

    public function back(User $actor, Discussion $discussion, int $stake): Position
    {
        if ((int) $discussion->user_id === (int) $actor->id) {
            throw new Refusal('hype_own_discussion');
        }
        if ($discussion->is_private || $discussion->hidden_at) {
            throw new Refusal('hype_not_backable');
        }
        if ($stake < $this->settings->minStake() || $stake > $this->settings->maxStake()) {
            throw new Refusal('hype_bad_stake');
        }

        $market = $this->markets->forDiscussion($discussion) ?? throw new Refusal('hype_not_backable');

        $position = $market->getConnection()->transaction(function () use ($market, $actor, $stake) {
            $now = Carbon::now();
            /** @var Market|null $locked */
            $locked = Market::query()->whereKey($market->id)->lockForUpdate()->first();
            if (! $locked || $locked->settled_at || $now->gte($locked->closes_at)) {
                throw new Refusal('hype_closed');
            }
            if ((int) $locked->user_id === (int) $actor->id) {
                throw new Refusal('hype_own_discussion');
            }

            $account = $this->lockAccount($actor, $now);
            if ($account->backs + 1 > $this->settings->maxBacks()) {
                throw new Refusal('hype_daily_backs');
            }
            if ($account->spent + $stake > $this->settings->allowance()) {
                throw new Refusal('hype_allowance');
            }

            $backers = Position::query()->where('market_id', $locked->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if (in_array((int) $actor->id, $backers, true)) {
                throw new Refusal('hype_already_backed');
            }

            $events = $this->engagement->load(new Collection([$locked]))[$locked->id] ?? [];
            $excluded = Repricer::excluded($locked, [...$backers, (int) $actor->id]);

            $position = new Position();
            $position->market_id = $locked->id;
            $position->user_id = (int) $actor->id;
            $position->season_id = Season::current()->id;
            $position->stake = $stake;
            $position->loss = $this->settings->loss();
            $position->entry_price = Pricing::at($events, $now->getTimestamp(), $excluded)['price'];
            $position->created_at = $now;
            $position->save();

            Account::query()->whereKey($actor->id)->update(['spent' => $account->spent + $stake, 'backs' => $account->backs + 1]);

            return $position;
        });

        // The backer's own engagement leaves the price for everyone, and the sparkline follows.
        $this->repricer->reprice(new Collection([$market->fresh()]));

        return $position;
    }

    /** The member's account row, locked, with a new day's spending reset. */
    private function lockAccount(User $actor, Carbon $now): Account
    {
        $today = $now->copy()->utc()->toDateString();
        Account::query()->insertOrIgnore(['user_id' => $actor->id, 'day' => $today, 'spent' => 0, 'backs' => 0]);

        /** @var Account $account */
        $account = Account::query()->whereKey($actor->id)->lockForUpdate()->first();
        if ($account->day?->toDateString() !== $today) {
            Account::query()->whereKey($actor->id)->update(['day' => $today, 'spent' => 0, 'backs' => 0]);
            $account->spent = 0;
            $account->backs = 0;
        }

        return $account;
    }
}
