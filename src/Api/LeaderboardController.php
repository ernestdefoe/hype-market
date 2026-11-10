<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Score;
use ErnestDefoe\HypeMarket\Season;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hype/leaderboard?season=<id>&limit=50
 *
 * 🚨 No N+1: one query on hype_scores (kept current by settlement, never
 * summed from positions here), one for its members, one for the seasons and
 * two for the viewer's own rank. The same for 5 rows or 100.
 */
class LeaderboardController extends Handler
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int) Arr::get($params, 'limit', 50)));
        $seasons = Season::query()->orderByDesc('id')->get();
        $current = $seasons->firstWhere('ended_at', null) ?? Season::current();
        $season = $seasons->firstWhere('id', (int) Arr::get($params, 'season')) ?? $current;

        $rows = Score::query()->where('season_id', $season->id)->where('settled', '>', 0)
            ->orderByDesc('profit')->orderByDesc('wins')->orderBy('staked')->orderBy('user_id')
            ->limit($limit)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->all() ?: [0])->get()->keyBy('id');

        $board = [];
        $rank = 0;
        $last = null;
        foreach ($rows->values() as $i => $row) {
            if ($last === null || $row->profit !== $last) {
                $rank = $i + 1;
                $last = $row->profit;
            }
            if ($user = $users->get($row->user_id)) {
                $board[] = $this->row($row, $user, $rank);
            }
        }

        $me = null;
        if (! $actor->isGuest()) {
            $mine = Score::query()->where('season_id', $season->id)->where('user_id', $actor->id)->where('settled', '>', 0)->first();
            if ($mine) {
                $better = Score::query()->where('season_id', $season->id)->where('settled', '>', 0)->where('profit', '>', $mine->profit)->count();
                $me = $this->row($mine, $actor, $better + 1);
            }
        }

        return new JsonResponse([
            'season' => $season->toRow(),
            'currentSeasonId' => $current->id,
            'seasons' => $seasons->map(fn (Season $s) => $s->toRow())->values()->all(),
            'rows' => $board,
            'me' => $me,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Score $row, User $user, int $rank): array
    {
        return [
            'rank' => $rank,
            'user' => $this->presenter->user($user),
            'profit' => $row->profit,
            'staked' => $row->staked,
            'settled' => $row->settled,
            'wins' => $row->wins,
            'roi' => $row->staked ? round($row->profit / $row->staked, 4) : 0,
            'bestMultiplier' => round($row->best_multiplier, 2),
        ];
    }
}
