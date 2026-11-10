<?php

namespace ErnestDefoe\HypeMarket\Notification;

use ErnestDefoe\HypeMarket\Market;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Alert: a call settled flat (no growth, too few people, or called off).
 * The subject is the market, which has an API resource (Api\MarketResource):
 * a subject without one 500s every notification list. No data, so a repeat
 * settlement's sync() matches the alert already sent.
 */
class HypeFlatBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Market $market)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->market;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'hypeFlat';
    }

    public static function getSubjectModel(): string
    {
        return Market::class;
    }
}
