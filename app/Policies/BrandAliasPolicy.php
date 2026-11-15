<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Brand aliases are part of the brand: tenant admin (docs/spec.md 5.2).
 */
class BrandAliasPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageBrands;
}
