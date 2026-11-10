<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Position;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/hype/discussions/{id}: the market bar under a discussion's first
 * post. A discussion the viewer can't see is a 404, the same as core's.
 */
class DiscussionMarketController extends Handler
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $discussion = Discussion::query()->whereVisibleTo($actor)->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $market = Market::query()->where('discussion_id', $discussion->id)->first();
        if ($market) {
            $market->setRelation('discussion', $discussion);
        }
        $mine = $market && ! $actor->isGuest() ? Position::query()->where('market_id', $market->id)->where('user_id', $actor->id)->first() : null;

        return new JsonResponse([
            'market' => $market ? $this->presenter->market($market, $actor, $mine) : null,
            'wallet' => $this->presenter->wallet($actor),
            'canModerate' => $actor->hasPermission(Settings::MODERATE),
        ]);
    }
}
