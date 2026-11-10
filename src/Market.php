<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discussion's market. The price only ever comes from Pricing over the
 * engagement Engagement loads; nothing a request sends moves it.
 *
 * @property int $id
 * @property int|null $discussion_id
 * @property int|null $user_id
 * @property string $title
 * @property Carbon $opened_at
 * @property Carbon $closes_at
 * @property Carbon $settles_at
 * @property float $price
 * @property int $participants
 * @property int $backers_count
 * @property int $staked
 * @property list<array{0: int, 1: float}>|null $history
 * @property Carbon|null $priced_at
 * @property float|null $final_price
 * @property Carbon|null $settled_at
 * @property Carbon|null $voided_at
 * @property-read Discussion|null $discussion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Position> $positions
 */
class Market extends AbstractModel
{
    protected $table = 'hype_markets';

    public $timestamps = true;

    protected $casts = [
        'discussion_id' => 'integer',
        'user_id' => 'integer',
        'opened_at' => 'datetime',
        'closes_at' => 'datetime',
        'settles_at' => 'datetime',
        'price' => 'float',
        'participants' => 'integer',
        'backers_count' => 'integer',
        'staked' => 'integer',
        'history' => 'array',
        'priced_at' => 'datetime',
        'final_price' => 'float',
        'settled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Position, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Only markets on discussions the actor can see (tags, approval, hiding all
     * apply). A market whose discussion is gone shows to nobody.
     *
     * @param Builder<Market> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        $query->whereIn('hype_markets.discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));
    }

    public function isOpen(?Carbon $now = null): bool
    {
        return ! $this->settled_at && ($now ?? Carbon::now())->lt($this->closes_at);
    }

    public function status(): string
    {
        return match (true) {
            (bool) $this->voided_at => 'void',
            (bool) $this->settled_at => 'settled',
            Carbon::now()->lt($this->closes_at) => 'open',
            default => 'closed',
        };
    }
}
