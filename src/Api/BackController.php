<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Backing;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/hype/discussions/{id}/back {stake}. Everything that decides
 * whether the back stands is in Backing, under the row locks.
 */
class BackController extends Handler
{
    public function __construct(
        private readonly Backing $backing,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);
        $actor->assertCan(Settings::BACK);

        $discussion = Discussion::query()->whereVisibleTo($actor)->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $stake = Arr::get(self::body($request), 'stake');
        if (! is_int($stake) && ! (is_string($stake) && ctype_digit($stake))) {
            return Errors::make(422, 'hype_bad_stake');
        }

        $position = $this->backing->back($actor, $discussion, (int) $stake);

        /** @var Market $market */
        $market = Market::query()->findOrFail($position->market_id);
        $market->setRelation('discussion', $discussion);

        return new JsonResponse([
            'market' => $this->presenter->market($market, $actor, $position),
            'wallet' => $this->presenter->wallet($actor),
        ], 201);
    }
}
