<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Reports are data every role may see (docs/spec.md 5.2, reader); removing one goes with the report schedules.
 */
class ReportPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageReports;
}
