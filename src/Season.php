<?php

namespace ErnestDefoe\HypeMarket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class Season extends AbstractModel
{
    protected $table = 'hype_seasons';

    public $timestamps = false;

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

    /** The open season. The migration makes the first; ending one starts the next in the same transaction. */
    public static function current(): self
    {
        $season = self::query()->whereNull('ended_at')->orderByDesc('id')->first();

        if (! $season) {
            $season = new self();
            $season->name = Carbon::now()->format('F Y');
            $season->started_at = Carbon::now();
            $season->save();
        }

        return $season;
    }

    /** @return array{id: int, name: string, startedAt: string, endedAt: string|null} */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'startedAt' => $this->started_at->toIso8601String(),
            'endedAt' => $this->ended_at?->toIso8601String(),
        ];
    }
}
