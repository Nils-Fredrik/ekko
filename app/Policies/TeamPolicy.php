<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;

/**
 * Tenants (docs/spec.md 5.2). Creating, deleting, changing the plan and acting as a tenant is for
 * the platform admin only. Everything inside a tenant goes through TenantAccess.
 */
class TeamPolicy
{
    public function __construct(private readonly TenantAccess $access) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Team $team): bool
    {
        return $this->access->canAccess($user, $team);
    }

    /**
     * Tenants are created by the platform admin; there is no self-service (docs/spec.md 1).
     */
    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, Team $team): bool
    {
        return $this->access->can($user, $team, TenantPermission::UpdateTenant);
    }

    public function addTeamMember(User $user, Team $team): bool
    {
        return $this->access->can($user, $team, TenantPermission::ManageMembers);
    }

    public function updateTeamMember(User $user, Team $team): bool
    {
        return $this->access->can($user, $team, TenantPermission::ManageMembers);
    }

    public function removeTeamMember(User $user, Team $team): bool
    {
        return $this->access->can($user, $team, TenantPermission::ManageMembers);
    }

    public function assignRole(User $user, Team $team, string $role): bool
    {
        $tenantRole = TenantRole::tryFrom($role);

        return $tenantRole !== null && $this->access->canAssignRole($user, $team, $tenantRole);
    }

    /**
     * Only the platform admin closes and deletes tenants, also partner clients (docs/spec.md 5.2).
     */
    public function delete(User $user, Team $team): bool
    {
        return $user->isPlatformAdmin() && ! $team->personal_team;
    }

    /**
     * Plan, quotas and engines (docs/spec.md 5.2).
     */
    public function changePlan(User $user, Team $team): bool
    {
        return $user->isPlatformAdmin();
    }

    public function actAs(User $user, Team $team): bool
    {
        return $user->isPlatformAdmin();
    }

    public function viewUsage(User $user, Team $team): bool
    {
        return $this->access->can($user, $team, TenantPermission::ViewUsage);
    }
}
