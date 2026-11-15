<?php

namespace App\Policies;

use App\Enums\TenantPermission;

class AnthropicInsightAllocationPolicy extends ReadOnlyTenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ViewUsage;
}
