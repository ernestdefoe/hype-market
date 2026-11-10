<?php

namespace ErnestDefoe\HypeMarket;

use Flarum\Settings\SettingsRepositoryInterface;

/** Every knob, read with its default and clamped, so a bad saved value can't open a farming route. */
final class Settings
{
    public const PREFIX = 'ernestdefoe-hype-market.';

    public const NAV = self::PREFIX.'nav';
    public const ALLOWANCE = self::PREFIX.'daily_allowance';
    public const MAX_STAKE = self::PREFIX.'max_stake';
    public const MIN_STAKE = self::PREFIX.'min_stake';
    public const MAX_BACKS = self::PREFIX.'max_backs';
    public const BACKING_HOURS = self::PREFIX.'backing_hours';
    public const WINDOW_HOURS = self::PREFIX.'window_hours';
    public const MIN_ACCOUNT_DAYS = self::PREFIX.'min_account_days';
    public const MIN_POSTS = self::PREFIX.'min_posts';
    public const MIN_PARTICIPANTS = self::PREFIX.'min_participants';
    public const SEASON_MODE = self::PREFIX.'season_mode';
    /** Percent of its stake a call loses if the discussion stays flat. 0 = nobody loses. */
    public const LOSS = self::PREFIX.'loss_percent';
    /** Stamped by every hype:tick, so the admin page can say when the scheduler last ran. */
    public const LAST_TICK = self::PREFIX.'last_tick';

    public const VIEW = 'hype.view';
    public const BACK = 'hype.back';
    public const MODERATE = 'hype.moderate';

    public const DEFAULTS = [
        self::NAV => true,
        self::ALLOWANCE => 100,
        self::MAX_STAKE => 50,
        self::MIN_STAKE => 5,
        self::MAX_BACKS => 5,
        self::BACKING_HOURS => 24,
        self::WINDOW_HOURS => 72,
        self::MIN_ACCOUNT_DAYS => 7,
        self::MIN_POSTS => 3,
        self::MIN_PARTICIPANTS => 2,
        self::SEASON_MODE => 'monthly',
        self::LOSS => 0,
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    private function int(string $key, int $min, int $max): int
    {
        $value = $this->settings->get($key);

        return max($min, min($max, is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key]));
    }

    public function allowance(): int
    {
        return $this->int(self::ALLOWANCE, 10, 100000);
    }

    public function minStake(): int
    {
        return min($this->int(self::MIN_STAKE, 1, 100000), $this->maxStake());
    }

    public function maxStake(): int
    {
        return min($this->int(self::MAX_STAKE, 1, 100000), $this->allowance());
    }

    public function maxBacks(): int
    {
        return $this->int(self::MAX_BACKS, 1, 100);
    }

    public function windowHours(): int
    {
        return $this->int(self::WINDOW_HOURS, 6, 24 * 14);
    }

    /** Never longer than the window: a back must leave room for the discussion to grow. */
    public function backingHours(): int
    {
        return min($this->int(self::BACKING_HOURS, 1, 24 * 14), $this->windowHours());
    }

    public function minAccountDays(): int
    {
        return $this->int(self::MIN_ACCOUNT_DAYS, 0, 365);
    }

    public function minPosts(): int
    {
        return $this->int(self::MIN_POSTS, 0, 1000);
    }

    public function minParticipants(): int
    {
        return $this->int(self::MIN_PARTICIPANTS, 1, 50);
    }

    /** Percent, 0–100. */
    public function loss(): int
    {
        return $this->int(self::LOSS, 0, 100);
    }

    public function monthlySeasons(): bool
    {
        return ($this->settings->get(self::SEASON_MODE) ?? 'monthly') !== 'manual';
    }

    public function nav(): bool
    {
        return (bool) ($this->settings->get(self::NAV) ?? true);
    }

    public function stampTick(int $time): void
    {
        $this->settings->set(self::LAST_TICK, (string) $time);
    }

    public function lastTick(): ?int
    {
        $value = $this->settings->get(self::LAST_TICK);

        return is_numeric($value) ? (int) $value : null;
    }
}
