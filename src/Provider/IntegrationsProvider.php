<?php

namespace ErnestDefoe\HypeMarket\Provider;

use ErnestDefoe\HypeMarket\Integrations;
use Flarum\Foundation\AbstractServiceProvider;

/** One Integrations per request, so the optional-extension switches below reach every service. */
class IntegrationsProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Integrations::class);
    }
}
