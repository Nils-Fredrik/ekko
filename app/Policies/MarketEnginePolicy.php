<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Engines and frequency per market are market settings: tenant admin (docs/spec.md 5.2).
 */
class MarketEnginePolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageMarkets;
}
