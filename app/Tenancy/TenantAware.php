<?php

namespace App\Tenancy;

/**
 * A queued job that works in one tenant. The tenant is set in the context by SetTenantContext.
 */
interface TenantAware
{
    public function tenantId(): int;
}
