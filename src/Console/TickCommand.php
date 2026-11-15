<?php

namespace ErnestDefoe\HypeMarket\Console;

use Carbon\Carbon;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Markets;
use ErnestDefoe\HypeMarket\Repricer;
use ErnestDefoe\HypeMarket\Scores;
use ErnestDefoe\HypeMarket\Seasons;
use ErnestDefoe\HypeMarket\Settings;
use ErnestDefoe\HypeMarket\Settler;
use Flarum\Console\AbstractCommand;

/**
 * Every five minutes (the scheduler):
 *  1. in monthly mode, the first run of a month starts the new season;
 *  2. opens markets for new discussions that don't have one yet;
 *  3. reprices every open market (two engagement queries per 200 markets);
 *  4. settles every market whose time is up. Settler locks each row and
 *     checks it again, so overlapping runs settle each market exactly once;
 *  5. recounts any member whose season total missed a settlement (Scores::heal).
 */
class TickCommand extends AbstractCommand
{
    public function __construct(
        private readonly Seasons $seasons,
        private readonly Markets $markets,
        private readonly Repricer $repricer,
        private readonly Settler $settler,
        private readonly Settings $settings,
        private readonly Scores $scores,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hype:tick')
            ->setDescription('Open, reprice and settle Hype Market markets, and start a new season when one is due.');
    }

    protected function fire(): int
    {
        $now = Carbon::now();
        $this->settings->stampTick($now->getTimestamp());

        $rolled = $this->seasons->rollMonthly($now);
        $opened = $this->markets->openNew();

        $repriced = 0;
        Market::query()->whereNull('settled_at')->orderBy('id')->chunkById(200, function ($markets) use (&$repriced, $now) {
            $this->repricer->reprice($markets, $now);
            $repriced += $markets->count();
        });

        $settled = 0;
        $due = Market::query()->whereNull('settled_at')->where('settles_at', '<=', $now)->orderBy('settles_at')->limit(500)->pluck('id');
        foreach ($due as $id) {
            $settled += $this->settler->settle((int) $id) ? 1 : 0;
        }

        $healed = $this->scores->heal();

        $this->info(($rolled ? 'New season. ' : '')."Opened {$opened}, repriced {$repriced}, settled {$settled}, recounted {$healed}.");

        return 0;
    }
}
