<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * The brand's standard measurement plan is a market setting: tenant admin (docs/spec.md 5.2 and 7.6).
 */
class BrandPlanEnginePolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageMarkets;
}
