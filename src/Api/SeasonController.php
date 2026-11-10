<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Refusal;
use ErnestDefoe\HypeMarket\Seasons;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/hype/seasons {name}: admins end the season and start the next one. */
class SeasonController extends Handler
{
    public function __construct(private readonly Seasons $seasons)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) (self::body($request)['name'] ?? '')));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 80) {
            throw new Refusal('hype_bad_season');
        }

        $result = $this->seasons->startNew($name);

        return new JsonResponse(['ended' => $result['ended']->toRow(), 'started' => $result['started']->toRow()], 201);
    }
}
