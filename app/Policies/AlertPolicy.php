<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Triggered alerts are data every role may see; acknowledging or removing one goes with the alert rules (tenant admin).
 */
class AlertPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageAlerts;
}
