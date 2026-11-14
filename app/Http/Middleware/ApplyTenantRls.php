<?php

namespace App\Http\Middleware;

use App\Tenancy\Rls\TenantRls;
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
 * The tenants are the current tenant. TenantAccess (T05) adds partner client tenants.
 */
final class ApplyTenantRls
{
    public function __construct(
        private readonly TenantRls $rls,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->rls->run(
            $this->allowedTenantIds(),
            fn (): Response => $next($request),
            fn (Response $response): bool => $response->getStatusCode() < 500,
        );
    }

    /**
     * @return list<int>
     */
    private function allowedTenantIds(): array
    {
        $tenantId = $this->context->id();

        return $tenantId === null ? [] : [$tenantId];
    }
}
