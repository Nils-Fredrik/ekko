<?php

namespace App\Tenancy;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single answer to "what may this user do in this tenant" (docs/spec.md 5.2). Policies use it,
 * and so do the tenant context (SetCurrentTenant), the tenant switcher and row-level security.
 *
 * A user's role in a tenant is:
 * 1. Admin, while a platform admin acts as this tenant (logged, shown as a banner), otherwise
 * 2. the stronger of the membership role (the owner counts as Admin) and PartnerAdvisor, when the
 *    tenant is a client of a partner tenant where the user is a partner advisor. A partner advisor
 *    is "as tenant admin" in every client (5.2), so a lower membership there cannot limit that.
 *
 * Closed tenants are only reachable for a platform admin acting as them.
 */
final class TenantAccess
{
    /** @var array<string, TenantRole|null> */
    private array $roles = [];

    public function __construct(private readonly ActingAs $actingAs) {}

    public function roleIn(User $user, Team $tenant): ?TenantRole
    {
        if ($this->actingAs->tenantIdFor($user) === $tenant->id) {
            return TenantRole::Admin;
        }

        return $this->ownRoleIn($user, $tenant);
    }

    /**
     * Whether the user may store this tenant as their current tenant (the switcher). Only real
     * access counts: acting as a tenant is session-only and is never stored (ADR 0004).
     */
    public function canSelect(User $user, Team $tenant): bool
    {
        return $this->ownRoleIn($user, $tenant) !== null;
    }

    /**
     * The role from membership or partner, without acting-as. Closed tenants give no role.
     */
    private function ownRoleIn(User $user, Team $tenant): ?TenantRole
    {
        if ($tenant->status === TenantStatus::Closed) {
            return null;
        }

        $key = "{$user->id}:{$tenant->id}";

        if (! array_key_exists($key, $this->roles)) {
            $this->roles[$key] = $this->strongest($this->membershipRole($user, $tenant), $this->partnerRole($user, $tenant));
        }

        return $this->roles[$key];
    }

    public function canAccess(User $user, Team $tenant): bool
    {
        return $this->roleIn($user, $tenant) !== null;
    }

    public function can(User $user, Team $tenant, TenantPermission $permission): bool
    {
        return $this->roleIn($user, $tenant)?->grants($permission) ?? false;
    }

    /**
     * Whether the user may give $role to a member of $tenant. The partner advisor role reaches
     * client tenants, so only a partner advisor (or a platform admin acting as the tenant) may
     * give it.
     */
    public function canAssignRole(User $user, Team $tenant, TenantRole $role): bool
    {
        if (! $this->can($user, $tenant, TenantPermission::ManageMembers)) {
            return false;
        }

        if ($role !== TenantRole::PartnerAdvisor) {
            return true;
        }

        // A client tenant cannot be a partner itself (one level, 5.1), so the role would mean nothing there.
        if ($tenant->partner_team_id !== null) {
            return false;
        }

        return $this->actingAs->tenantIdFor($user) === $tenant->id
            || $this->membershipRole($user, $tenant) === TenantRole::PartnerAdvisor;
    }

    /**
     * The tenants in the switcher: memberships and partner clients, without closed tenants. The
     * tenant a platform admin acts as is not listed: acting-as is session-only, shown by the banner
     * and the navigation, and is never stored as the current tenant.
     *
     * @return Collection<int, Team>
     */
    public function accessibleTenants(User $user): Collection
    {
        $memberships = $user->ownedTeams->merge($user->teams);

        $partnerIds = $user->teams
            ->filter(fn (Team $team) => $team->getRelationValue('membership')?->getAttribute('role') === TenantRole::PartnerAdvisor->value)
            ->pluck('id');

        // Clients of an open partner where the user is partner advisor are accessible by definition
        // (the partner role is the strongest role), so they are taken straight from one query:
        // open clients of open partners. This keeps the switcher at a fixed number of queries.
        $clients = $partnerIds->isEmpty()
            ? collect()
            : Team::query()
                ->whereIn('partner_team_id', $partnerIds)
                ->where('status', '!=', TenantStatus::Closed->value)
                ->whereHas('partner', fn ($partner) => $partner->where('status', '!=', TenantStatus::Closed->value))
                ->get();

        // The switcher and switchTeam() must never disagree: memberships go through canSelect()
        // (this hides closed tenants).
        return $memberships
            ->unique('id')
            ->filter(fn (Team $team) => $this->canSelect($user, $team))
            ->merge($clients)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * The tenants row-level security allows while the user works in $current (app.tenant_ids):
     * the current tenant, and its clients when the user is a partner advisor in it. A platform
     * admin acting as a tenant sees only that tenant.
     *
     * @return list<int>
     */
    public function rlsTenantIds(User $user, Team $current): array
    {
        if (! $this->canAccess($user, $current)) {
            return [];
        }

        $ids = [$current->id];

        if ($this->actingAs->tenantIdFor($user) !== $current->id
            && $this->membershipRole($user, $current) === TenantRole::PartnerAdvisor) {
            $clientIds = Team::query()
                ->where('partner_team_id', $current->id)
                ->where('status', '!=', TenantStatus::Closed->value)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = [...$ids, ...$clientIds];
        }

        return $ids;
    }

    /**
     * Forget cached roles, after membership or partner changes in the same request.
     */
    public function flush(): void
    {
        $this->roles = [];
    }

    private function membershipRole(User $user, Team $tenant): ?TenantRole
    {
        if ($tenant->user_id === $user->id) {
            return TenantRole::Admin;
        }

        $role = $tenant->users()
            ->whereKey($user->id)
            ->first()
            ?->getRelationValue('membership')
            ?->getAttribute('role');

        return is_string($role) ? TenantRole::tryFrom($role) : null;
    }

    private function strongest(?TenantRole $membership, ?TenantRole $partner): ?TenantRole
    {
        if ($partner === null || $membership === TenantRole::Admin || $membership === TenantRole::PartnerAdvisor) {
            return $membership ?? $partner;
        }

        return $partner;
    }

    private function partnerRole(User $user, Team $tenant): ?TenantRole
    {
        if ($tenant->partner_team_id === null) {
            return null;
        }

        $partner = Team::query()->find($tenant->partner_team_id);

        if ($partner === null || $partner->status === TenantStatus::Closed) {
            return null;
        }

        return $this->membershipRole($user, $partner) === TenantRole::PartnerAdvisor
            ? TenantRole::PartnerAdvisor
            : null;
    }
}
