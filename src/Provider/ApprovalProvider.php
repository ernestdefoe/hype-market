<?php

namespace ErnestDefoe\HypeMarket\Provider;

use ErnestDefoe\HypeMarket\Integrations;
use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while flarum/approval is enabled (Extend\Conditional in extend.php). */
class ApprovalProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving(Integrations::class, function (Integrations $integrations) {
            $integrations->approval = true;
        });
    }
}
