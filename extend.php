<?php

/*
 * Hype Market: members back the new discussions they think will take off.
 *
 * Every new discussion opens a market (Markets). Its price follows real
 * engagement (Engagement → Pricing), with the author, every backer and
 * low-trust accounts left out and every member capped. Members back young
 * discussions out of a free daily allowance (Backing, under row locks), the
 * scheduler reprices open markets and settles due ones (hype:tick → Repricer,
 * Settler), and season totals are rebuilt from positions (Scores). Every
 * step can run twice without changing the result.
 */

use ErnestDefoe\HypeMarket\Api;
use ErnestDefoe\HypeMarket\Console\TickCommand;
use ErnestDefoe\HypeMarket\Gdpr\HypeData;
use ErnestDefoe\HypeMarket\Http\PageContent;
use ErnestDefoe\HypeMarket\Listener\OpenMarket;
use ErnestDefoe\HypeMarket\Notification;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Discussion\Event\Started;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The page, the market bar and the back form are a chunk in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/hype', 'hype', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * Plain JSON endpoints, not JSON:API resources, on purpose: none of them is CRUD on a model.
     * Markets are only ever created and priced by the server (a member can't create, edit or
     * delete one), so there is no create/update/delete to expose. The reads are computed views
     * across several models in one answer (a market card carries the viewer's own position and
     * today's allowance; the portfolio, leaderboard and hottest list are aggregates), and the
     * writes are actions with their own race-safe rules (back, call off, end a season). The
     * `hype-markets` resource (Api\MarketResource) still exists, read-only with no endpoints,
     * as the subject of notifications.
     */
    (new Extend\Routes('api'))
        ->get('/hype/markets', 'hype.markets', Api\MarketsController::class)
        ->get('/hype/discussions/{id:\d+}', 'hype.discussion', Api\DiscussionMarketController::class)
        ->post('/hype/discussions/{id:\d+}/back', 'hype.back', Api\BackController::class)
        ->post('/hype/markets/{id:\d+}/void', 'hype.void', Api\VoidController::class)
        ->get('/hype/portfolio', 'hype.portfolio', Api\PortfolioController::class)
        ->get('/hype/leaderboard', 'hype.leaderboard', Api\LeaderboardController::class)
        ->post('/hype/seasons', 'hype.seasons.create', Api\SeasonController::class),

    new Extend\ApiResource(Api\MarketResource::class),

    // The chip and the market bar: one query per request for every young discussion on it, none for older ones.
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\Notification())
        ->type(Notification\HypePaidBlueprint::class, ['alert'])
        ->type(Notification\HypeFlatBlueprint::class, ['alert']),

    // String literals, not Settings constants: extend.php is read while a deploy swaps vendor/, and
    // autoloading our own class here would fatal every request in that window.
    (new Extend\Settings())
        ->default('ernestdefoe-hype-market.nav', true)
        ->default('ernestdefoe-hype-market.daily_allowance', 100)
        ->default('ernestdefoe-hype-market.max_stake', 50)
        ->default('ernestdefoe-hype-market.min_stake', 5)
        ->default('ernestdefoe-hype-market.max_backs', 5)
        ->default('ernestdefoe-hype-market.backing_hours', 24)
        ->default('ernestdefoe-hype-market.window_hours', 72)
        ->default('ernestdefoe-hype-market.min_account_days', 7)
        ->default('ernestdefoe-hype-market.min_posts', 3)
        ->default('ernestdefoe-hype-market.min_participants', 2)
        ->default('ernestdefoe-hype-market.season_mode', 'monthly')
        ->default('ernestdefoe-hype-market.loss_percent', 0)
        ->serializeToForum('hypeNav', 'ernestdefoe-hype-market.nav', 'boolval')
        ->serializeToForum('hypeLoss', 'ernestdefoe-hype-market.loss_percent', 'intval'),

    (new Extend\Event())
        ->listen(Started::class, OpenMarket::class),

    (new Extend\Console())
        ->command(TickCommand::class)
        ->schedule(TickCommand::class, function (ScheduledEvent $event) {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    // A member's calls, season totals and spending in their data export; erased with them.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(HypeData::class),
        ]),
];
