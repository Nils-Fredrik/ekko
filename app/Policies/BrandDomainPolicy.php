<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Brand domains are part of the brand: tenant admin (docs/spec.md 5.2).
 */
class BrandDomainPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageBrands;
}
