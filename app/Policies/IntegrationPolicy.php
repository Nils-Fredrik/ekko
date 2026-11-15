<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Integrations (ClickHouse, GA4) hold credentials in their config, so both seeing and changing them are tenant settings: tenant admin.
 */
class IntegrationPolicy extends TenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::UpdateTenant;

    protected TenantPermission $manage = TenantPermission::UpdateTenant;
}
