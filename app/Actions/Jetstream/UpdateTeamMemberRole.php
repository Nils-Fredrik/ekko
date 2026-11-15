<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole as JetstreamUpdateTeamMemberRole;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

/**
 * Jetstream's role update, plus TeamPolicy::assignRole: only a partner advisor (or a platform
 * admin acting as the tenant) may give the partner advisor role (docs/spec.md 5.2).
 */
class UpdateTeamMemberRole extends JetstreamUpdateTeamMemberRole
{
    /**
     * @param  User  $user
     * @param  Team  $team
     * @param  int  $teamMemberId
     */
    public function update($user, $team, $teamMemberId, string $role): void
    {
        Gate::forUser($user)->authorize('updateTeamMember', $team);

        Validator::make(['role' => $role], ['role' => ['required', 'string', new Role]])->validate();

        Gate::forUser($user)->authorize('assignRole', [$team, $role]);

        $previous = $team->users()->wherePivot('user_id', $teamMemberId)->value('team_user.role');

        $team->users()->updateExistingPivot($teamMemberId, ['role' => $role]);

        $member = Jetstream::findUserByIdOrFail($teamMemberId);
        app(AuditLog::class)->memberRoleChanged($user, $team, $member, $previous, $role);

        TeamMemberUpdated::dispatch($team->fresh(), $member);
    }
}
