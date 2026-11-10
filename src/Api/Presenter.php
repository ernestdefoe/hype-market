<?php

namespace ErnestDefoe\HypeMarket\Api;

use Carbon\Carbon;
use ErnestDefoe\HypeMarket\Account;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Position;
use ErnestDefoe\HypeMarket\Pricing;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * Markets and positions as the forum reads them. A page of markets costs the
 * query that found them (with discussion.user eager-loaded) plus ONE for the
 * viewer's positions in them, however many there are.
 */
class Presenter
{
    public function __construct(
        private readonly SlugManager $slugs,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param Collection<int, Market> $markets discussion.user loaded, already filtered to what the actor may see
     * @return list<array<string, mixed>>
     */
    public function markets(Collection $markets, User $actor): array
    {
        $mine = $actor->isGuest() || $markets->isEmpty() ? new Collection() : Position::query()
            ->whereIn('market_id', $markets->pluck('id')->all())
            ->where('user_id', $actor->id)
            ->get()->keyBy('market_id');

        return $markets->map(fn (Market $m) => $this->market($m, $actor, $mine->get($m->id)))->values()->all();
    }

    /** @return array<string, mixed> */
    public function market(Market $market, User $actor, ?Position $mine): array
    {
        $discussion = $market->discussion;
        $history = $market->history ?? [];
        $first = $history[0][1] ?? Pricing::BASE;

        return [
            'id' => $market->id,
            'discussion' => $discussion ? $this->discussion($discussion) : null,
            'author' => $discussion?->user ? $this->user($discussion->user) : null,
            'status' => $market->status(),
            'openedAt' => $market->opened_at->toIso8601String(),
            'closesAt' => $market->closes_at->toIso8601String(),
            'settlesAt' => $market->settles_at->toIso8601String(),
            'price' => round($market->price, 2),
            'change' => $first > 0 ? round($market->price / $first - 1, 4) : 0,
            'momentum' => self::momentum($market),
            'participants' => $market->participants,
            'participantsNeeded' => $this->settings->minParticipants(),
            'backers' => $market->backers_count,
            'staked' => $market->staked,
            'history' => array_map(fn ($p) => [(int) $p[0], (float) $p[1]], $history),
            'canBack' => ! $mine && $this->mayBack($market, $actor),
            'mine' => $mine ? $this->position($mine, $market) : null,
        ];
    }

    /** Price change over the last six hours: what "hottest right now" sorts by. */
    public static function momentum(Market $market): float
    {
        $history = $market->history ?? [];
        if (! $history) {
            return 0.0;
        }
        $since = Carbon::now()->subHours(6)->getTimestamp();
        $then = $history[0][1];
        foreach ($history as [$at, $price]) {
            if ($at <= $since) {
                $then = $price;
            }
        }

        return $then > 0 ? round($market->price / $then - 1, 4) : 0.0;
    }

    public function mayBack(Market $market, User $actor): bool
    {
        return ! $actor->isGuest()
            && $actor->hasPermission(Settings::BACK)
            && $market->isOpen()
            && (int) $market->user_id !== (int) $actor->id;
    }

    /** @return array<string, mixed> */
    public function position(Position $position, Market $market): array
    {
        $settled = (bool) $position->settled_at;
        $estimate = $settled ? null : Pricing::multiplier($position->entry_price, $market->price, $market->participants, $this->settings->minParticipants(), $position->loss);

        return [
            'id' => $position->id,
            'stake' => $position->stake,
            'loss' => $position->loss,
            'entryPrice' => round($position->entry_price, 2),
            'createdAt' => $position->created_at->toIso8601String(),
            'settledAt' => $position->settled_at?->toIso8601String(),
            'exitPrice' => $position->exit_price !== null ? round($position->exit_price, 2) : null,
            'multiplier' => $settled ? $position->multiplier : $estimate,
            'value' => $settled ? $position->payout : Pricing::payout($position->stake, (float) $estimate),
            'profit' => $settled ? $position->profit : Pricing::payout($position->stake, (float) $estimate) - $position->stake,
        ];
    }

    /** @return array<string, mixed>|null the viewer's spending today, and the limits */
    public function wallet(User $actor): ?array
    {
        if ($actor->isGuest()) {
            return null;
        }
        $today = Account::today((int) $actor->id);
        $allowance = $this->settings->allowance();

        return [
            'allowance' => $allowance,
            'spent' => $today['spent'],
            'left' => max(0, $allowance - $today['spent']),
            'backs' => $today['backs'],
            'maxBacks' => $this->settings->maxBacks(),
            'minStake' => $this->settings->minStake(),
            'maxStake' => $this->settings->maxStake(),
            'resetsAt' => Carbon::now()->utc()->addDay()->startOfDay()->toIso8601String(),
            'canBack' => $actor->hasPermission(Settings::BACK),
        ];
    }

    /** @return array{id: int, username: string, displayName: string, slug: string, avatarUrl: string|null} */
    public function user(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ];
    }

    /** @return array{id: int, title: string, slug: string, commentCount: int, createdAt: string|null} */
    private function discussion(Discussion $discussion): array
    {
        return [
            'id' => (int) $discussion->id,
            'title' => (string) $discussion->title,
            'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
            'commentCount' => (int) $discussion->comment_count,
            'createdAt' => $discussion->created_at?->toIso8601String(),
        ];
    }
}
