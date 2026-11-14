<?php

namespace App\Jobs\Middleware;

use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAware;
use Closure;
use LogicException;

/**
 * Runs a tenant job in a transaction with row-level security for the job's tenant only
 * (docs/spec.md 5.3, layer 2). A job without a tenant is refused.
 */
final class ApplyTenantRls
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantAware) {
            throw new LogicException($job::class.' must implement '.TenantAware::class.' to use '.self::class.'.');
        }

        // Job middleware is created with new in TenantJob::middleware(), so it resolves its
        // dependencies from the container instead of taking them in the constructor.
        return app(TenantRls::class)->run([$job->tenantId()], fn () => $next($job));
    }
}
