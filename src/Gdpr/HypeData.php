<?php

namespace ErnestDefoe\HypeMarket\Gdpr;

use ErnestDefoe\HypeMarket\Account;
use ErnestDefoe\HypeMarket\Market;
use ErnestDefoe\HypeMarket\Position;
use ErnestDefoe\HypeMarket\Score;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: every call they made (with the discussion's title only
 * where they can still see it), their season totals and today's spending.
 * Erasing removes all of that; markets on their own discussions stay (other
 * members backed them) without an author.
 */
class HypeData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-hype-market.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-hype-market.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $positions = Position::query()->where('user_id', $this->user->id)->orderBy('created_at')->get();
        $visible = Market::query()->visibleTo($this->user)
            ->whereIn('hype_markets.id', $positions->pluck('market_id')->unique()->values()->all() ?: [0])
            ->with('discussion')->get()->keyBy('id');

        $rows = [
            'calls' => $positions->map(fn (Position $p) => [
                'discussion' => $visible->get($p->market_id)?->discussion?->title,
                'season_id' => $p->season_id,
                'stake' => $p->stake,
                'loss_percent' => $p->loss,
                'entry_price' => $p->entry_price,
                'exit_price' => $p->exit_price,
                'multiplier' => $p->multiplier,
                'payout' => $p->payout,
                'profit' => $p->profit,
                'backed_at' => $p->created_at->toIso8601String(),
                'settled_at' => $p->settled_at?->toIso8601String(),
            ])->all(),
            'seasons' => Score::query()->where('user_id', $this->user->id)->get(['season_id', 'profit', 'staked', 'settled', 'wins', 'best_multiplier'])->toArray(),
            'today' => Account::query()->whereKey($this->user->id)->first(['day', 'spent', 'backs'])?->toArray(),
        ];

        return [['hype-market/hype-market.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Position::query()->where('user_id', $this->user->id)->delete();
        Score::query()->where('user_id', $this->user->id)->delete();
        Account::query()->whereKey($this->user->id)->delete();
        Market::query()->where('user_id', $this->user->id)->update(['user_id' => null]);
    }
}
