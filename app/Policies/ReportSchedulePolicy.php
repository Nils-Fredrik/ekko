<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Report schedules and recipients: tenant admin (docs/spec.md 5.2, "rapportmottakere").
 */
class ReportSchedulePolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageReports;
}
