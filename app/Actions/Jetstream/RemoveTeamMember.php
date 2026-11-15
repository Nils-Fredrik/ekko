<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Events\TeamMemberRemoved;

class RemoveTeamMember implements RemovesTeamMembers
{
    /**
     * Remove the team member from the given team.
     */
    public function remove(User $user, Team $team, User $teamMember): void
    {
        $this->authorize($user, $team, $teamMember);

        $this->ensureUserDoesNotOwnTeam($teamMember, $team);

        // Someone outside the tenant (or a removal sent twice) removes nothing, and the revision log
        // must not record a removal that did not happen.
        $role = $team->users()->wherePivot('user_id', $teamMember->id)->value('team_user.role');
        abort_if($role === null, 404);

        $team->removeUser($teamMember);

        app(AuditLog::class)->memberRemoved($user, $team, $teamMember, (string) $role);

        TeamMemberRemoved::dispatch($team, $teamMember);
    }

    /**
     * Authorize that the user can remove the team member.
     */
    protected function authorize(User $user, Team $team, User $teamMember): void
    {
        if (! Gate::forUser($user)->check('removeTeamMember', $team) &&
            $user->id !== $teamMember->id) {
            throw new AuthorizationException;
        }
    }

    /**
     * Ensure that the currently authenticated user does not own the team.
     */
    protected function ensureUserDoesNotOwnTeam(User $teamMember, Team $team): void
    {
        if ($teamMember->id === $team->owner->id) {
            throw ValidationException::withMessages([
                'team' => [__('You may not leave a team that you created.')],
            ])->errorBag('removeTeamMember');
        }
    }
}
