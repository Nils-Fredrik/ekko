<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Markets: tenant admin (docs/spec.md 5.2).
 */
class MarketPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageMarkets;
}
