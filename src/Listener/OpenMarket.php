<?php

namespace ErnestDefoe\HypeMarket\Listener;

use ErnestDefoe\HypeMarket\Markets;
use Flarum\Discussion\Event\Started;

/** A new discussion gets its market at once (one insert), so it can be backed from its first minute. */
class OpenMarket
{
    public function __construct(private readonly Markets $markets)
    {
    }

    public function handle(Started $event): void
    {
        $this->markets->forDiscussion($event->discussion);
    }
}
