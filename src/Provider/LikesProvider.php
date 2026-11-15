<?php

namespace ErnestDefoe\HypeMarket\Provider;

use ErnestDefoe\HypeMarket\Integrations;
use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while flarum/likes is enabled (Extend\Conditional in extend.php). */
class LikesProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving(Integrations::class, function (Integrations $integrations) {
            $integrations->likes = true;
        });
    }
}
