<?php

namespace App\Scheduling;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Connection;

/**
 * The database cache store with PostgreSQL-safe locks: a lock that is already held is found with an insert that
 * ignores the conflict, so the surrounding transaction is not aborted (Laravel's DatabaseLock catches the unique
 * violation, which PostgreSQL has already used to abort the transaction).
 */
final class PostgresLockStore extends DatabaseStore
{
    public function __construct(private readonly Connection $locks, string $table, string $prefix, string $lockTable)
    {
        parent::__construct($locks, $table, $prefix, $lockTable);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new PostgresCollectionLock($this->locks, $this->lockTable, $this->prefix.$name, $seconds, $owner);
    }
}
