<?php

namespace App\Scheduling;

use Illuminate\Contracts\Cache\Lock;
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
}
