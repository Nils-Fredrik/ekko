<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * The revision log (docs/spec.md 16) is written by AuditLog and the models, never by users. It shows who
 * changed roles and settings, so only the tenant admin reads it.
 */
class ActivityPolicy extends ReadOnlyTenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ManageMembers;
}
