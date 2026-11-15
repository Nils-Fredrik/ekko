<?php

namespace App\Scheduling;

use Illuminate\Cache\DatabaseLock;

/** PostgreSQL-safe conflict handling, including inside a surrounding transaction. */
final class PostgresCollectionLock extends DatabaseLock
{
    public function acquire(): bool
    {
        if ($this->connection->table($this->table)->insertOrIgnore([
            'key' => $this->name, 'owner' => $this->owner, 'expiration' => $this->expiresAt(),
        ]) === 1) {
            return true;
        }

        return $this->connection->table($this->table)->where('key', $this->name)
            ->where(fn ($q) => $q->where('owner', $this->owner)->orWhere('expiration', '<=', $this->currentTime()))
            ->update(['owner' => $this->owner, 'expiration' => $this->expiresAt()]) === 1;
    }
}
