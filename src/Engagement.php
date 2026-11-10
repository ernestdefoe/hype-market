<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * The engagement that can move a price, for any number of markets in two
 * queries (replies, likes), never one per market.
 *
 * What is left out here, for every market:
 *  - anything after the market's settle time or before it opened (posts merged
 *    in from older discussions), and the discussion's first post;
 *  - hidden, private and (with flarum/approval) unapproved posts;
 *  - replies with almost no text ("+1", "this");
 *  - replies and likes from low-trust accounts: an unconfirmed email, fewer
 *    than `min_posts` comments OUTSIDE this discussion (an account can't reply
 *    its way into trust in the thread it is lifting), or an account younger than
 *    `min_account_days` WHEN IT ACTED (aging into trust doesn't bring old activity);
 *  - likes on your own post.
 *
 * What Pricing then leaves out per market: the discussion's author and every
 * backer, and anything past each member's cap.
 */
class Engagement
{
    /** Shortest reply, in visible characters, that counts. */
    public const MIN_REPLY_CHARS = 12;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param Collection<int, Market> $markets
     * @return array<int, list<array{t: int, u: int, k: string}>> events by market id, oldest first
     */
    public function load(Collection $markets): array
    {
        $byDiscussion = [];
        $until = [];
        $from = [];
        foreach ($markets as $market) {
            if ($market->discussion_id) {
                $byDiscussion[$market->discussion_id] = $market->id;
                $until[$market->id] = $market->settles_at->getTimestamp();
                $from[$market->id] = $market->opened_at->getTimestamp();
            }
        }

        $events = array_fill_keys(array_values($byDiscussion), []);
        if (! $byDiscussion) {
            return $events;
        }

        $ids = array_keys($byDiscussion);
        $latest = Carbon::createFromTimestamp(max($until));
        $minPosts = $this->settings->minPosts();
        $minAge = $this->settings->minAccountDays() * 86400;
        $approval = $this->extensions->isEnabled('flarum-approval');

        $replies = $this->db->table('posts')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->whereIn('posts.discussion_id', $ids)
            ->where('posts.type', 'comment')
            ->where('posts.number', '>', 1)
            ->whereNull('posts.hidden_at')
            ->where('posts.is_private', false)
            ->when($approval, fn ($q) => $q->where('posts.is_approved', true))
            ->where('posts.created_at', '<=', $latest)
            ->where('users.is_email_confirmed', true)
            ->where('users.comment_count', '>=', $minPosts)
            ->get(['posts.discussion_id', 'posts.user_id', 'posts.created_at', 'posts.content', 'users.joined_at', 'users.comment_count']);

        // Each member's comments in each of these discussions: they don't count toward min_posts here.
        $inThread = [];
        if ($minPosts > 0) {
            foreach ($this->db->table('posts')
                ->whereIn('discussion_id', $ids)->where('type', 'comment')->whereNull('hidden_at')->whereNotNull('user_id')
                ->groupBy('discussion_id', 'user_id')
                ->selectRaw('discussion_id, user_id, COUNT(*) as n')->get() as $row) {
                $inThread[(int) $row->discussion_id][(int) $row->user_id] = (int) $row->n;
            }
        }
        $established = fn (object $row, int $memberId) => (int) $row->comment_count - ($inThread[(int) $row->discussion_id][$memberId] ?? 0) >= $minPosts;

        foreach ($replies as $row) {
            $marketId = $byDiscussion[(int) $row->discussion_id];
            $at = Carbon::parse($row->created_at)->getTimestamp();
            if ($at > $until[$marketId] || $at < $from[$marketId] || ! $this->trusted($row->joined_at, $at, $minAge)
                || ! $established($row, (int) $row->user_id) || ! self::substantial((string) $row->content)) {
                continue;
            }
            $events[$marketId][] = ['t' => $at, 'u' => (int) $row->user_id, 'k' => 'r'];
        }

        if ($this->extensions->isEnabled('flarum-likes')) {
            $likes = $this->db->table('post_likes')
                ->join('posts', 'posts.id', '=', 'post_likes.post_id')
                ->join('users', 'users.id', '=', 'post_likes.user_id')
                ->whereIn('posts.discussion_id', $ids)
                ->whereNull('posts.hidden_at')
                ->where('posts.is_private', false)
                ->whereColumn('post_likes.user_id', '<>', 'posts.user_id')
                ->whereNotNull('post_likes.created_at')
                ->where('post_likes.created_at', '<=', $latest)
                ->where('users.is_email_confirmed', true)
                ->where('users.comment_count', '>=', $minPosts)
                ->get(['posts.discussion_id', 'post_likes.user_id', 'post_likes.created_at', 'users.joined_at', 'users.comment_count']);

            foreach ($likes as $row) {
                $marketId = $byDiscussion[(int) $row->discussion_id];
                $at = Carbon::parse($row->created_at)->getTimestamp();
                if ($at > $until[$marketId] || $at < $from[$marketId] || ! $this->trusted($row->joined_at, $at, $minAge) || ! $established($row, (int) $row->user_id)) {
                    continue;
                }
                $events[$marketId][] = ['t' => $at, 'u' => (int) $row->user_id, 'k' => 'l'];
            }
        }

        foreach ($events as &$list) {
            usort($list, fn ($a, $b) => $a['t'] <=> $b['t']);
        }

        return $events;
    }

    /** Old enough when they acted. No join date at all = not trusted. */
    private function trusted(mixed $joinedAt, int $at, int $minAge): bool
    {
        if ($minAge === 0) {
            return true;
        }

        return $joinedAt !== null && Carbon::parse($joinedAt)->getTimestamp() + $minAge <= $at;
    }

    /** A reply worth counting: enough visible text once markup is gone. */
    public static function substantial(string $content): bool
    {
        // A quote of someone else's words is not the replier's own.
        $own = preg_replace('#<(QUOTE|blockquote)\b[^>]*>.*?</\1>#si', '', $content) ?? '';
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($own), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';

        return mb_strlen(trim($text)) >= self::MIN_REPLY_CHARS;
    }
}
