<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the request in a transaction with row-level security for the tenants the request may see
 * (docs/spec.md 5.3, layer 2). Requests without a tenant see no tenant-owned rows.
 *
 * The transaction is rolled back when the response is a server error, so a failed request does
 * not leave half-finished writes.
 *
 * The tenants come from TenantAccess::rlsTenantIds(): the current tenant, plus its client tenants
 * when the user is a partner advisor in it.
 */
final class ApplyTenantRls
{
    public function __construct(
        private readonly TenantRls $rls,
        private readonly TenantContext $context,
        private readonly TenantAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->rls->run(
            $this->allowedTenantIds($request),
            fn (): Response => $next($request),
            fn (Response $response): bool => $response->getStatusCode() < 500,
        );
    }

    /**
     * @return list<int>
     */
    private function allowedTenantIds(Request $request): array
    {
        $tenantId = $this->context->id();
        $user = $request->user();

        if ($tenantId === null || ! $user instanceof User) {
            return [];
        }

        $tenant = Team::query()->find($tenantId);

        return $tenant === null ? [] : $this->access->rlsTenantIds($user, $tenant);
    }
}
