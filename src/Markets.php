<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;

/**
 * Opening markets. A discussion gets one while it is young enough to back:
 * when it starts, on the next tick (one that waited for approval), or on its
 * first back. Its times are fixed when it opens, so changing the settings later
 * never moves a market people already hold.
 */
class Markets
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /** Can this discussion have a market at all (never private, hidden, unapproved or authorless)? */
    public function eligible(Discussion $discussion): bool
    {
        return $discussion->user_id
            && ! $discussion->is_private
            && ! $discussion->hidden_at
            && (! $this->extensions->isEnabled('flarum-approval') || (bool) ($discussion->getAttribute('is_approved') ?? true))
            && $discussion->created_at
            && $discussion->created_at->gt(Carbon::now()->subHours($this->settings->backingHours()));
    }

    /** The discussion's market; opened now if it has none and may have one. */
    public function forDiscussion(Discussion $discussion): ?Market
    {
        $market = Market::query()->where('discussion_id', $discussion->id)->first();
        if ($market || ! $this->eligible($discussion)) {
            return $market;
        }

        // A second request opening the same market at the same moment hits the unique key and is ignored.
        Market::query()->insertOrIgnore($this->row($discussion));

        return Market::query()->where('discussion_id', $discussion->id)->first();
    }

    /** Markets for young discussions that don't have one yet (the tick). One query to find them. */
    public function openNew(): int
    {
        $since = Carbon::now()->subHours($this->settings->backingHours());
        $discussions = Discussion::query()
            ->where('created_at', '>', $since)
            ->whereNotNull('user_id')
            ->whereNull('hidden_at')
            ->where('is_private', false)
            ->when($this->extensions->isEnabled('flarum-approval'), fn ($q) => $q->where('is_approved', true))
            ->whereNotIn('id', Market::query()->whereNotNull('discussion_id')->select('discussion_id'))
            ->limit(500)
            ->get();

        $rows = $discussions->map(fn (Discussion $d) => $this->row($d))->all();
        if ($rows) {
            Market::query()->insertOrIgnore($rows);
        }

        return count($rows);
    }

    /** @return array<string, mixed> */
    private function row(Discussion $discussion): array
    {
        $opened = $discussion->created_at->copy();
        $now = Carbon::now();

        return [
            'discussion_id' => $discussion->id,
            'user_id' => $discussion->user_id,
            'title' => mb_substr((string) $discussion->title, 0, 200),
            'opened_at' => $opened,
            'closes_at' => $opened->copy()->addHours($this->settings->backingHours()),
            'settles_at' => $opened->copy()->addHours($this->settings->windowHours()),
            'price' => Pricing::BASE,
            'history' => json_encode([[$opened->getTimestamp(), Pricing::BASE]]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
