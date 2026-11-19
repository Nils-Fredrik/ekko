<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The weekly generation bookkeeping (ADR 0034) is written and read by the system only.
 */
class ActionGenerationPolicy extends ReadOnlyTenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $model): bool
    {
        return false;
    }
}
