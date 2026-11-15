<?php

namespace ErnestDefoe\HypeMarket;

/**
 * Which optional extensions Hype Market works with on this forum. Both start
 * off; extend.php switches each on with Extend\Conditional only when that
 * extension is enabled (Provider\LikesProvider, Provider\ApprovalProvider).
 */
final class Integrations
{
    /** flarum/likes: likes count toward prices. */
    public bool $likes = false;

    /** flarum/approval: posts and discussions awaiting approval are left out. */
    public bool $approval = false;
}
