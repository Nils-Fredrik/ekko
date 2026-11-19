<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * The facts a brand is checked against (docs/spec.md 11.6): the tenant admin (and partner advisor) enters and
 * approves them, every role reads them.
 */
class BrandFactPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageBrands;
}
