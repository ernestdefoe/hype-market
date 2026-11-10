<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $market_id
 * @property int $user_id
 * @property int|null $season_id
 * @property int $stake
 * @property int $loss
 * @property float $entry_price
 * @property float|null $exit_price
 * @property float|null $multiplier
 * @property int|null $payout
 * @property int|null $profit
 * @property list<int>|null $contributors
 * @property Carbon $created_at
 * @property Carbon|null $settled_at
 * @property-read Market $market
 * @property-read User $user
 */
class Position extends AbstractModel
{
    protected $table = 'hype_positions';

    public $timestamps = false;

    protected $casts = [
        'market_id' => 'integer',
        'user_id' => 'integer',
        'season_id' => 'integer',
        'stake' => 'integer',
        'loss' => 'integer',
        'entry_price' => 'float',
        'exit_price' => 'float',
        'multiplier' => 'float',
        'payout' => 'integer',
        'profit' => 'integer',
        'contributors' => 'array',
        'created_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
