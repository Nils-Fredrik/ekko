<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;

class BotLogUploadPolicy extends ReadOnlyTenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::UpdateTenant;

    public function create(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, TenantPermission::UpdateTenant);
    }
}
