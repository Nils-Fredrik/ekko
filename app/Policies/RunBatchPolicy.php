<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;

/**
 * Run batches are planned by the system. A manual check within the quota starts one, which the
 * tenant admin and the editor may do (docs/spec.md 5.2, "kjøre manuelle kontroller"). Changing or
 * deleting a batch is left to the system.
 */
class RunBatchPolicy extends ReadOnlyTenantResourcePolicy
{
    public function create(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, TenantPermission::RunChecks);
    }
}
