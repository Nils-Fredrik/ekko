<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Brands: tenant admin (docs/spec.md 5.2).
 */
class BrandPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageBrands;
}
