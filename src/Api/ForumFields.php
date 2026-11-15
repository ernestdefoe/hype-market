<?php

namespace ErnestDefoe\HypeMarket\Api;

use ErnestDefoe\HypeMarket\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The viewer's own Hype Market permissions: their groups are already loaded, so no query. */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('hypeCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission(Settings::VIEW)),
            Schema\Boolean::make('hypeCanBack')
                ->get(fn ($forum, Context $context) => ! $context->getActor()->isGuest() && $context->getActor()->hasPermission(Settings::BACK)),
        ];
    }
}
