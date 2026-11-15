<?php

namespace ErnestDefoe\HypeMarket;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Named cache locks, held around every write that the database row locks
 * also guard. On MySQL, MariaDB and PostgreSQL the SELECT … FOR UPDATE does
 * the work; on SQLite it does nothing, and these keep two writers apart.
 *
 * They never wait: a request that finds a lock held fails fast (the member
 * is asked to try again, the scheduler tries again next run), so no PHP
 * worker sits in a sleep. Taken in sorted order, released in reverse.
 */
class Locks
{
    /** Longest a lock is held: well past any write here, and freed if a process dies holding it. */
    public const SECONDS = 30;

    public function __construct(private readonly Repository $cache)
    {
    }

    /**
     * $run while holding every lock in $names, or $busy if any is held elsewhere.
     *
     * @template T
     * @template B
     * @param list<string> $names
     * @param callable(): T $run
     * @param callable(): B $busy
     * @return T|B
     */
    public function attempt(array $names, callable $run, callable $busy): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $run();
        }

        $names = array_values(array_unique($names));
        sort($names);
        $held = [];
        try {
            foreach ($names as $name) {
                $lock = $store->lock('ernestdefoe-hype-market.'.$name, self::SECONDS);
                if (! $lock->get()) {
                    return $busy();
                }
                $held[] = $lock;
            }

            return $run();
        } finally {
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
    }
}
