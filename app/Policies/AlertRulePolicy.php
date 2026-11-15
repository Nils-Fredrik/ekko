<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Alert rules: tenant admin (docs/spec.md 5.2, "sette varsler").
 */
class AlertRulePolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageAlerts;
}
