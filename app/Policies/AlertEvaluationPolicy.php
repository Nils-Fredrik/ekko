<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * The record that the alerts of a day have been evaluated (docs/spec.md 11.1). Written by the alert job
 * only; the tenant admin may read it, like the alert rules it belongs to.
 */
class AlertEvaluationPolicy extends ReadOnlyTenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ManageAlerts;
}
