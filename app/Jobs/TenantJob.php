<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetTenantContext;
use App\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base class for queued jobs that work in a tenant (docs/spec.md 5.3).
 *
 * The tenant is always a constructor parameter. Subclasses call parent::__construct($tenantId)
 * and merge parent::middleware() if they add their own middleware.
 */
abstract class TenantJob implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(public readonly int $tenantId) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }
}
