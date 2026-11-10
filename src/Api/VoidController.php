<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Refusal;
use ErnestDefoe\HypeMarket\Settings;
use ErnestDefoe\HypeMarket\Settler;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/hype/markets/{id}/void: a moderator calls off a market that was
 * gamed. Every stake comes back flat; nobody profits. Settled markets stay.
 */
class VoidController extends Handler
{
    public function __construct(private readonly Settler $settler)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::MODERATE);

        $market = Market::query()->visibleTo($actor)->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $this->settler->settle($market->id, true)) {
            throw new Refusal('hype_already_settled');
        }

        return new JsonResponse(['voided' => true]);
    }
}
