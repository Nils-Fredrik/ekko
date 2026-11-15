<?php

namespace App\Policies;

use App\Enums\TenantPermission;

class TenantApiKeyPolicy extends TenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::UpdateTenant;

    protected TenantPermission $manage = TenantPermission::UpdateTenant;
}
