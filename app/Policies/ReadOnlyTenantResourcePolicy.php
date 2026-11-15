<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for tenant-owned data that the system writes (runs, answers, aggregates, usage, the
 * revision log). Users may read it with $view; creating, changing and deleting happens in jobs and
 * actions that do not go through a policy, so no role is allowed to.
 */
abstract class ReadOnlyTenantResourcePolicy extends TenantResourcePolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
