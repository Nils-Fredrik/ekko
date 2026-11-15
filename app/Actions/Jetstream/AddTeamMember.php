<?php

namespace App\Actions\Jetstream;

use App\Enums\TenantRole;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;

class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     */
    public function add(User $user, Team $team, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        // Case-insensitive: an invitation for "Ann@x.no" is for the user "ann@x.no".
        $email = Str::lower($email);

        $this->validate($team, $email, $role);

        // The partner advisor role reaches client tenants, so not every admin may give it. An
        // accepted invitation was already checked when it was sent (InviteTeamMember), and Jetstream
        // accepts it on behalf of the owner, so it is not checked again here.
        $invited = $role !== null && $this->isInvited($team, $email, $role);

        if ($role !== null && ! $invited) {
            Gate::forUser($user)->authorize('assignRole', [$team, $role]);
        }

        // Also for an accepted invitation: the tenant may have become a client tenant since it was
        // sent, and a client tenant cannot be a partner (one level, docs/spec.md 5.1).
        if ($role === TenantRole::PartnerAdvisor->value && $team->partner_team_id !== null) {
            throw new AuthorizationException;
        }

        $newTeamMember = User::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();

        AddingTeamMember::dispatch($team, $newTeamMember);

        $team->users()->attach(
            $newTeamMember, ['role' => $role]
        );

        TeamMemberAdded::dispatch($team, $newTeamMember);

        // An accepted invitation is added on behalf of the owner, but it is the member who acted.
        app(AuditLog::class)->memberAdded($invited ? $newTeamMember : $user, $team, $newTeamMember, (string) $role);
    }

    private function isInvited(Team $team, string $email, string $role): bool
    {
        return $team->teamInvitations()->whereRaw('lower(email) = ?', [$email])->where('role', $role)->exists();
    }

    /**
     * Validate the add member operation.
     */
    protected function validate(Team $team, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules())->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for adding a team member.
     *
     * @return array<string, Rule|array<int, mixed>|string>
     */
    protected function rules(): array
    {
        return array_filter([
            'email' => ['required', 'email', function (string $attribute, mixed $value, Closure $fail) {
                if (! User::query()->whereRaw('lower(email) = ?', [Str::lower((string) $value)])->exists()) {
                    $fail(__('We were unable to find a registered user with this email address.'));
                }
            }],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role]
                            : null,
        ]);
    }

    /**
     * Ensure that the user is not already on the team.
     */
    protected function ensureUserIsNotAlreadyOnTeam(Team $team, string $email): Closure
    {
        return function ($validator) use ($team, $email) {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}
