<?php

namespace ErnestDefoe\HypeMarket\Api;

use Carbon\Carbon;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Pricing;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Api\Context;
use Flarum\Discussion\Discussion;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * The `hype` attribute on discussions, for the "backed by 12" chip and the
 * market bar: ONE query per request for every discussion on the page, and
 * none at all for discussions too old to have a live market.
 *
 * Flarum 2 resolves a getter that returns a Closure after every other field,
 * so each young discussion queues its id and the first closure loads them all.
 * State is keyed by the request (WeakMap), never kept across requests.
 */
class DiscussionHype
{
    /**
     * Static: the getter resolves a fresh instance per field, and they must share one queue.
     *
     * @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, loaded: array<int, array<string, mixed>|null>}>|null
     */
    private static ?WeakMap $byRequest = null;

    public function __construct(private readonly Settings $settings)
    {
        self::$byRequest ??= new WeakMap();
    }

    /** @return (\Closure(): (array<string, mixed>|null))|null */
    public function field(Discussion $discussion, Context $context): ?\Closure
    {
        // Settled markets keep their chip for two days after the window, then it goes.
        $horizon = Carbon::now()->subHours($this->settings->windowHours() + 48);
        if (! $discussion->created_at || $discussion->created_at->lt($horizon) || ! $context->getActor()->hasPermission(Settings::VIEW)) {
            return null;
        }

        $request = $context->request;
        $state = self::$byRequest[$request] ?? ['queued' => [], 'loaded' => []];
        if (! array_key_exists($discussion->id, $state['loaded'])) {
            $state['queued'][$discussion->id] = true;
        }
        self::$byRequest[$request] = $state;

        return fn () => $this->get($request, (int) $discussion->id);
    }

    /** @return array<string, mixed>|null */
    private function get(ServerRequestInterface $request, int $id): ?array
    {
        $state = self::$byRequest[$request];
        if ($state['queued']) {
            $ids = array_keys($state['queued']);
            $markets = Market::query()->whereIn('discussion_id', $ids)
                ->get(['id', 'discussion_id', 'price', 'backers_count', 'closes_at', 'settled_at', 'voided_at', 'final_price'])
                ->keyBy('discussion_id');
            foreach ($ids as $queued) {
                $market = $markets->get($queued);
                $state['loaded'][$queued] = $market ? [
                    'price' => round($market->price, 2),
                    'change' => round($market->price / Pricing::BASE - 1, 4),
                    'backers' => $market->backers_count,
                    'status' => $market->status(),
                    'closesAt' => $market->closes_at->toIso8601String(),
                ] : null;
            }
            $state['queued'] = [];
            self::$byRequest[$request] = $state;
        }

        return $state['loaded'][$id] ?? null;
    }
}
