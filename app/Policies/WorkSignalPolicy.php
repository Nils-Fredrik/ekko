<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Work signals are the bookkeeping of event-driven processing (docs/spec.md 7.1, ADR 0032). They are written
 * and read by the system only, so no role may see or change them.
 */
class WorkSignalPolicy extends ReadOnlyTenantResourcePolicy
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
