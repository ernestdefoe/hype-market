<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A member's spending today (UTC). The row a back locks.
 *
 * @property int $user_id
 * @property Carbon|null $day
 * @property int $spent
 * @property int $backs
 */
class Account extends AbstractModel
{
    protected $table = 'hype_accounts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = ['user_id' => 'integer', 'day' => 'date', 'spent' => 'integer', 'backs' => 'integer'];

    /** @return array{spent: int, backs: int} today's numbers, without writing anything */
    public static function today(int $userId): array
    {
        $account = self::query()->find($userId);
        if (! $account || $account->day?->toDateString() !== Carbon::now()->utc()->toDateString()) {
            return ['spent' => 0, 'backs' => 0];
        }

        return ['spent' => $account->spent, 'backs' => $account->backs];
    }
}
