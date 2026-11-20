<?php

namespace App\Jobs;

use App\Jobs\Middleware\ApplyTenantRls;
use App\Jobs\Middleware\SetTenantContext;
use App\Scheduling\CollectionLocks;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base class for queued jobs that work in a tenant (docs/spec.md 5.3). The job runs with its tenant
 * in the context and in a transaction with row-level security for that tenant only.
 *
 * The tenant is always a constructor parameter. Subclasses call parent::__construct($tenantId)
 * and merge parent::middleware() if they add their own middleware.
 *
 * A subclass that is ShouldBeUnique takes its unique lock through {@see self::uniqueVia()}.
 */
abstract class TenantJob implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(public readonly int $tenantId) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /** Internal run identifier for log correlation; most jobs do not belong to a single run. */
    public function logRunId(): ?int
    {
        return null;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Context first (layer 1), then row-level security for the job's tenant (layer 2).
        return [new SetTenantContext, new ApplyTenantRls];
    }

    /**
     * The cache for the lock of a unique job (ShouldBeUnique, ShouldBeUniqueUntilProcessing). A unique job may be
     * dispatched inside a transaction, and the database store's own lock aborts the surrounding PostgreSQL
     * transaction (SQLSTATE 25P02) when the job is already queued. Every unique job therefore uses the
     * PostgreSQL-safe lock (T49, T21-F1). Laravel only asks unique jobs for it.
     */
    public function uniqueVia(): Repository
    {
        return CollectionLocks::uniqueJobCache();
    }
}
