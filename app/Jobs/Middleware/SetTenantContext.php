<?php

namespace App\Jobs\Middleware;

use App\Tenancy\TenantAware;
use App\Tenancy\TenantContext;
use Closure;
use LogicException;

/**
 * Sets the job's tenant in the context while the job runs, and restores the previous context
 * afterwards (docs/spec.md 5.3). A job without a tenant is refused.
 */
final class SetTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantAware) {
            throw new LogicException($job::class.' must implement '.TenantAware::class.' to use '.self::class.'.');
        }

        return app(TenantContext::class)->run($job->tenantId(), fn () => $next($job));
    }
}
