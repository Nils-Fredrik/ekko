<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;

/**
 * Alerts are triggered by the system and seen by every role (docs/spec.md 11.1). Acknowledging or
 * removing one goes with the alert rules (tenant admin); no role creates one.
 */
class AlertPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageAlerts;

    public function create(User $user): bool
    {
        return false;
    }
}
