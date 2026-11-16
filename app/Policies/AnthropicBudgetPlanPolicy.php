<?php

namespace App\Policies;

use App\Enums\TenantPermission;

class AnthropicBudgetPlanPolicy extends ReadOnlyTenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ViewUsage;
}
