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
use ErnestDefoe\HypeMarket\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
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
        ->fields(fn () => [
            Schema\Arr::make('hype')
                ->nullable()
                ->get(fn (Discussion $discussion, Context $context) => resolve(Api\DiscussionHype::class)->field($discussion, $context)),
        ]),

    // The viewer's own permissions: their groups are already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('hypeCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission(Settings::VIEW)),
            Schema\Boolean::make('hypeCanBack')
                ->get(fn ($forum, Context $context) => ! $context->getActor()->isGuest() && $context->getActor()->hasPermission(Settings::BACK)),
        ]),

    (new Extend\Notification())
        ->type(Notification\HypePaidBlueprint::class, ['alert'])
        ->type(Notification\HypeFlatBlueprint::class, ['alert']),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->default(Settings::ALLOWANCE, Settings::DEFAULTS[Settings::ALLOWANCE])
        ->default(Settings::MAX_STAKE, Settings::DEFAULTS[Settings::MAX_STAKE])
        ->default(Settings::MIN_STAKE, Settings::DEFAULTS[Settings::MIN_STAKE])
        ->default(Settings::MAX_BACKS, Settings::DEFAULTS[Settings::MAX_BACKS])
        ->default(Settings::BACKING_HOURS, Settings::DEFAULTS[Settings::BACKING_HOURS])
        ->default(Settings::WINDOW_HOURS, Settings::DEFAULTS[Settings::WINDOW_HOURS])
        ->default(Settings::MIN_ACCOUNT_DAYS, Settings::DEFAULTS[Settings::MIN_ACCOUNT_DAYS])
        ->default(Settings::MIN_POSTS, Settings::DEFAULTS[Settings::MIN_POSTS])
        ->default(Settings::MIN_PARTICIPANTS, Settings::DEFAULTS[Settings::MIN_PARTICIPANTS])
        ->default(Settings::SEASON_MODE, Settings::DEFAULTS[Settings::SEASON_MODE])
        ->default(Settings::LOSS, Settings::DEFAULTS[Settings::LOSS])
        ->serializeToForum('hypeNav', Settings::NAV, 'boolval')
        ->serializeToForum('hypeLoss', Settings::LOSS, 'intval'),

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
