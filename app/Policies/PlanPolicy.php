<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

/**
 * Packages are managed by the platform admin only (docs/spec.md 12.1).
 */
class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->isPlatformAdmin();
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->isPlatformAdmin();
    }
}
