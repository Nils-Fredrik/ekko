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
 * A user's role in a tenant is, in order:
 * 1. Admin, while a platform admin acts as this tenant (logged, shown as a banner)
 * 2. the membership role (the owner counts as Admin)
 * 3. PartnerAdvisor, when the tenant is a client of a partner tenant where the user is a partner advisor
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

        if ($tenant->status === TenantStatus::Closed) {
            return null;
        }

        return $this->roles["{$user->id}:{$tenant->id}"] ??= $this->membershipRole($user, $tenant)
            ?? $this->partnerRole($user, $tenant);
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

        return $this->actingAs->tenantIdFor($user) === $tenant->id
            || $this->membershipRole($user, $tenant) === TenantRole::PartnerAdvisor;
    }

    /**
     * The tenants in the switcher: memberships and partner clients, without closed tenants, plus
     * the tenant a platform admin acts as.
     *
     * @return Collection<int, Team>
     */
    public function accessibleTenants(User $user): Collection
    {
        $memberships = $user->ownedTeams->merge($user->teams);

        $partnerIds = $user->teams
            ->filter(fn (Team $team) => $team->getRelationValue('membership')?->getAttribute('role') === TenantRole::PartnerAdvisor->value)
            ->pluck('id');

        $clients = $partnerIds->isEmpty()
            ? collect()
            : Team::query()->whereIn('partner_team_id', $partnerIds)->get();

        $actingAsId = $this->actingAs->tenantIdFor($user);
        $actingAs = $actingAsId === null ? collect() : Team::query()->whereKey($actingAsId)->get();

        return $memberships->merge($clients)
            ->filter(fn (Team $team) => $team->status !== TenantStatus::Closed)
            ->merge($actingAs)
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
