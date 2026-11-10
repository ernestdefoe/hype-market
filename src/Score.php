<?php

namespace ErnestDefoe\HypeMarket;

use Flarum\Database\AbstractModel;

/**
 * A member's season totals, rebuilt from their settled positions (Scores).
 *
 * @property int $id
 * @property int $season_id
 * @property int $user_id
 * @property int $profit
 * @property int $staked
 * @property int $settled
 * @property int $wins
 * @property float $best_multiplier
 */
class Score extends AbstractModel
{
    protected $table = 'hype_scores';

    public $timestamps = false;

    protected $casts = [
        'season_id' => 'integer',
        'user_id' => 'integer',
        'profit' => 'integer',
        'staked' => 'integer',
        'settled' => 'integer',
        'wins' => 'integer',
        'best_multiplier' => 'float',
    ];
}
