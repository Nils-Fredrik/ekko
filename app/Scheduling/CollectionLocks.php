<?php

namespace App\Scheduling;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CollectionLocks
{
    public static function make(string $key, int $seconds): Lock
    {
        $connection = DB::connection(config('cache.stores.database.lock_connection') ?? config('cache.stores.database.connection'));
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Collection requires shared PostgreSQL locks.');
        }

        return new PostgresCollectionLock($connection, config('cache.stores.database.lock_table') ?: 'cache_locks', config('cache.prefix').$key, $seconds);
    }

    /**
     * The cache for the lock of a unique job that is dispatched inside a transaction (ShouldBeUnique::uniqueVia).
     * The database store's own lock aborts the surrounding PostgreSQL transaction when the job is already queued.
     * Any other cache (tests use the array store) is used as it is.
     */
    public static function uniqueJobCache(): CacheRepository
    {
        $config = config('cache.stores.database');
        $connection = config('cache.default') === 'database' ? DB::connection($config['lock_connection'] ?? $config['connection'] ?? null) : null;
        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql') {
            return Cache::store();
        }

        // Same name, table and prefix as the database store, so a lock can still be released through it.
        return new Repository(new PostgresLockStore($connection, $config['table'] ?? 'cache', config('cache.prefix'), ($config['lock_table'] ?? null) ?: 'cache_locks'), ['store' => 'database']);
    }
}
