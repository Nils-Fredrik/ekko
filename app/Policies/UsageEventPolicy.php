<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Usage is written by the system and seen by the tenant admin (docs/spec.md 5.2, "se forbruk").
 */
class UsageEventPolicy extends ReadOnlyTenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ViewUsage;
}
