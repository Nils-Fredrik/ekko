<?php

namespace App\Policies;

use App\Enums\TenantPermission;

final class ReportLinkPolicy extends TenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ManageReports;

    protected TenantPermission $manage = TenantPermission::ManageReports;
}
