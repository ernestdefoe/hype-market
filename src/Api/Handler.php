<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Refusal;
use Flarum\User\Exception\PermissionDeniedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Our controllers answer a Refusal with its code; everything else goes to Flarum's error handling. */
abstract class Handler implements RequestHandlerInterface
{
    abstract protected function respond(ServerRequestInterface $request): ResponseInterface;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->respond($request);
        } catch (Refusal $refusal) {
            return Errors::make($refusal->status, $refusal->reason);
        }
    }

    /** @return array<string, mixed> */
    protected static function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }

    protected static function deny(): never
    {
        throw new PermissionDeniedException();
    }
}
