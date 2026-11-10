<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Position;
use ErnestDefoe\HypeMarket\Score;
use ErnestDefoe\HypeMarket\Season;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hype/portfolio: the viewer's open positions (each with its
 * market's sparkline), their last 30 settled ones, today's wallet and their
 * season line. A fixed number of queries for any number of positions.
 *
 * A position on a discussion the viewer can no longer see (deleted, moved to
 * a tag they can't read, hidden) keeps its numbers but loses its title.
 */
class PortfolioController extends Handler
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        $open = Position::query()->where('user_id', $actor->id)->whereNull('settled_at')->orderByDesc('created_at')->limit(100)->get();
        $settled = Position::query()->where('user_id', $actor->id)->whereNotNull('settled_at')->orderByDesc('settled_at')->orderByDesc('id')->limit(30)->get();
        $positions = $open->concat($settled);

        $markets = Market::query()->whereIn('id', $positions->pluck('market_id')->unique()->values()->all() ?: [0])->get()->keyBy('id');
        $visible = Discussion::query()->whereVisibleTo($actor)
            ->whereIn('discussions.id', $markets->pluck('discussion_id')->filter()->values()->all() ?: [0])
            ->with('user')->get()->keyBy('id');
        foreach ($markets as $market) {
            $market->setRelation('discussion', $visible->get($market->discussion_id));
        }

        $row = fn (Position $p) => $this->presenter->market($markets->get($p->market_id), $actor, $p);

        $season = Season::current();
        $score = Score::query()->where('season_id', $season->id)->where('user_id', $actor->id)->first();
        $rank = $score && $score->settled > 0
            ? Score::query()->where('season_id', $season->id)->where('settled', '>', 0)->where('profit', '>', $score->profit)->count() + 1
            : null;

        return new JsonResponse([
            'open' => $open->map($row)->values()->all(),
            'settled' => $settled->map($row)->values()->all(),
            'wallet' => $this->presenter->wallet($actor),
            'season' => [
                'season' => $season->toRow(),
                'profit' => $score->profit ?? 0,
                'settled' => $score->settled ?? 0,
                'wins' => $score->wins ?? 0,
                'bestMultiplier' => $score->best_multiplier ?? 0,
                'rank' => $rank,
                'atStake' => (int) $open->sum('stake'),
            ],
        ]);
    }
}
