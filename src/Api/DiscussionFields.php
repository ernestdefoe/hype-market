<?php

namespace ErnestDefoe\HypeMarket\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

/**
 * The `hype` attribute on discussions: the "backed by 12" chip and the market
 * bar. One query per request for every young discussion on it (DiscussionHype),
 * none for older ones.
 */
class DiscussionFields
{
    public function __construct(private readonly DiscussionHype $hype)
    {
    }

    /** @return list<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('hype')
                ->nullable()
                ->get(fn (Discussion $discussion, Context $context) => $this->hype->field($discussion, $context)),
        ];
    }
}
