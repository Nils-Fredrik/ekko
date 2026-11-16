<?php

namespace App\Actions\Jetstream;

use App\Enums\TenantStatus;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;

/**
 * "Delete" closes the tenant (T39-F6, docs/spec.md 16): nobody reaches it from now on, and
 * ekko:purge-closed-tenants deletes it with all its data, raw answers in GCS included, after
 * Team::PURGE_AFTER_DAYS. The days leave room to export the data first. Only with a recently confirmed
 * password (the form asks for it first), so a hijacked admin session alone cannot close a tenant.
 */
class DeleteTeam implements DeletesTeams
{
    public function delete(Team $team): void
    {
        // Checked here and not with the password.confirm middleware, so the access checks (ValidateTeamDeletion)
        // still answer first: another tenant gives 403, not a redirect to the password page.
        $confirmedAt = (int) request()->session()->get('auth.password_confirmed_at', 0);
        if (Date::now()->getTimestamp() - $confirmedAt > (int) config('auth.password_timeout')) {
            throw ValidationException::withMessages(['password' => __('app.tenant_close.confirm_password')])->errorBag('deleteTeam');
        }

        if ($team->status === TenantStatus::Closed && $team->closed_at !== null) {
            return;
        }

        $team->forceFill(['status' => TenantStatus::Closed, 'closed_at' => Date::now()])->save();

        $user = request()->user();
        if ($user instanceof User) {
            app(AuditLog::class)->tenantClosed($user, $team);
        }
    }
}
