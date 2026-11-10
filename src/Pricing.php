<?php

namespace ErnestDefoe\HypeMarket;

/**
 * The price, from a market's engagement events. Pure: the same events, cut-off
 * and exclusions always give the same price, which is what lets settlement run
 * twice, or late, and land on the same numbers.
 *
 *   raw   = Σ per member: their first replies (1, then ½, then ¼; no more after 3)
 *         + 3 for each member who replied at all
 *         + ½ per like, at most 3 likes per member
 *   price = 10 × (1 + ln(1 + raw / 4))
 *
 * The log gives diminishing returns, so a pile-on is worth less than a
 * conversation, and the per-member caps mean no one account can carry a price.
 */
final class Pricing
{
    public const BASE = 10.0;
    public const REPLY_WEIGHTS = [1.0, 0.5, 0.25];
    public const PARTICIPANT = 3.0;
    public const LIKE = 0.5;
    public const LIKES_PER_MEMBER = 3;
    public const SCALE = 4.0;
    public const MAX_MULTIPLIER = 10.0;

    /**
     * @param list<array{t: int, u: int, k: string}> $events oldest first
     * @param array<int, true> $excluded member ids whose engagement never counts here
     * @return array{price: float, participants: int, contributors: list<int>}
     */
    public static function at(array $events, int $until, array $excluded, int $since = 0): array
    {
        $replies = [];
        $likes = [];
        $contributors = [];
        foreach ($events as $event) {
            if ($event['t'] > $until) {
                break;
            }
            if (isset($excluded[$event['u']])) {
                continue;
            }
            if ($event['k'] === 'r') {
                $replies[$event['u']] = ($replies[$event['u']] ?? 0) + 1;
            } else {
                $likes[$event['u']] = ($likes[$event['u']] ?? 0) + 1;
            }
            if ($event['t'] > $since) {
                $contributors[$event['u']] = true;
            }
        }

        $raw = 0.0;
        foreach ($replies as $count) {
            $raw += self::PARTICIPANT;
            foreach (array_slice(self::REPLY_WEIGHTS, 0, $count) as $weight) {
                $raw += $weight;
            }
        }
        foreach ($likes as $count) {
            $raw += self::LIKE * min($count, self::LIKES_PER_MEMBER);
        }

        return [
            'price' => self::price($raw),
            'participants' => count($replies),
            'contributors' => array_map('intval', array_keys($contributors)),
        ];
    }

    public static function price(float $raw): float
    {
        return round(self::BASE * (1 + log(1 + max(0.0, $raw) / self::SCALE)), 2);
    }

    /**
     * What a position pays per hype staked: its growth (never below 1, counted
     * as 1 unless enough different members took part), less the call's loss
     * share, between 0 and MAX_MULTIPLIER. With no loss share (the default) a
     * quiet discussion pays the stake back and nobody loses hype; with 50%, a
     * flat call pays back half and a call has to grow 1.5× to break even.
     */
    public static function multiplier(float $entry, float $exit, int $participants, int $minParticipants, int $lossPercent = 0): float
    {
        $growth = $participants < $minParticipants || $entry <= 0 ? 1.0 : max(1.0, $exit / $entry);

        return round(max(0.0, min(self::MAX_MULTIPLIER, $growth - $lossPercent / 100)), 4);
    }

    public static function payout(int $stake, float $multiplier): int
    {
        return (int) floor($stake * $multiplier + 1e-9);
    }
}
