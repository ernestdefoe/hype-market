<?php

namespace ErnestDefoe\HypeMarket\Api;

use Carbon\Carbon;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hype/markets?sort=hot|new|top|closing&limit=20
 *
 * Markets the viewer can see that haven't settled. "hot" (hottest right now)
 * sorts by the price change over the last six hours, read from the stored
 * sparkline, so it costs no engagement queries: one for the markets (with
 * discussion.user), one for the viewer's positions, one for their wallet.
 */
class MarketsController extends Handler
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $params = $request->getQueryParams();
        $sort = (string) Arr::get($params, 'sort', 'hot');
        $limit = min(50, max(1, (int) Arr::get($params, 'limit', 20)));

        $query = Market::query()->visibleTo($actor)->whereNull('settled_at')->with('discussion.user');

        $markets = match ($sort) {
            'new' => $query->where('closes_at', '>', Carbon::now())->orderByDesc('opened_at')->limit($limit)->get(),
            'top' => $query->orderByDesc('price')->orderByDesc('id')->limit($limit)->get(),
            'closing' => $query->where('closes_at', '>', Carbon::now())->orderBy('closes_at')->limit($limit)->get(),
            // Open markets are at most a window old, so this set is small; momentum is read from each sparkline.
            default => $query->orderByDesc('price')->limit(300)->get()
                ->sortByDesc(fn (Market $m) => [Presenter::momentum($m), $m->price])
                ->take($limit)->values(),
        };

        return new JsonResponse([
            'markets' => $this->presenter->markets($markets, $actor),
            'wallet' => $this->presenter->wallet($actor),
        ]);
    }
}
