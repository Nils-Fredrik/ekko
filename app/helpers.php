<?php

use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;

if (! function_exists('current_tenant_id')) {
    /**
     * The tenant the current request or job works in (docs/spec.md 5.3).
     *
     * @throws MissingTenantContextException
     */
    function current_tenant_id(): int
    {
        return app(TenantContext::class)->require();
    }
}
