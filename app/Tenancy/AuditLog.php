<?php

namespace App\Tenancy;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Audit entries for access-related actions (docs/spec.md 16), written as structured entries to the
 * application log (Cloud Logging in production) with user_id and tenant_id.
 *
 * Board task:44 adds the revision log (spatie/laravel-activitylog) once T08 has installed it.
 */
final class AuditLog
{
    public function actingAsStarted(User $admin, Team $tenant, string $reason): void
    {
        Log::info('acting_as.started', [
            'user_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'reason' => $reason,
        ]);
    }

    public function actingAsStopped(User $admin, Team $tenant): void
    {
        Log::info('acting_as.stopped', [
            'user_id' => $admin->id,
            'tenant_id' => $tenant->id,
        ]);
    }
}
