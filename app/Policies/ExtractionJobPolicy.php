<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Extraction jobs are the bookkeeping of the batch extraction (docs/spec.md 8.2). They are written
 * and read by the system only, so no role may see or change them.
 */
class ExtractionJobPolicy extends ReadOnlyTenantResourcePolicy
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
