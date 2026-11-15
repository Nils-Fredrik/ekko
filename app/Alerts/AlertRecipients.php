<?php

namespace App\Alerts;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who gets an alert by e-mail: the roles in the alert's payload (recipient_roles, default tenant_admin).
 * tenant_admin is the owner, the admins of the tenant and the partner advisors of its partner tenant, the
 * people who may manage alerts (docs/spec.md 5.2). platform_admin is every platform admin.
 */
final class AlertRecipients
{
    /** @var array<string, Collection<int, User>> */
    private array $cache = [];

    /**
     * @return Collection<int, User>
     */
    public function forAlert(Alert $alert, Team $tenant): Collection
    {
        $roles = $alert->payload['recipient_roles'] ?? ['tenant_admin'];
        $users = collect();

        foreach ((array) $roles as $role) {
            $users = $users->merge(match ($role) {
                'tenant_admin' => $this->tenantAdmins($tenant),
                'platform_admin' => $this->platformAdmins(),
                default => collect(),
            });
        }

        return $users->unique('id')->filter(fn (User $user) => $user->email !== '')->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function tenantAdmins(Team $tenant): Collection
    {
        return $this->cache["tenant:{$tenant->id}"] ??= $this->loadTenantAdmins($tenant);
    }

    /**
     * @return Collection<int, User>
     */
    private function loadTenantAdmins(Team $tenant): Collection
    {
        $ids = [$tenant->user_id, ...$tenant->users()->wherePivot('role', TenantRole::Admin->value)->pluck('users.id')->all()];

        // As in TenantAccess::partnerRole(): the advisors of a partner tenant that is closed have no access.
        $partner = $tenant->partner_team_id === null ? null : Team::query()->find($tenant->partner_team_id);
        if ($partner !== null && $partner->status !== TenantStatus::Closed) {
            $ids = [...$ids, ...$partner->users()->wherePivot('role', TenantRole::PartnerAdvisor->value)->pluck('users.id')->all()];
        }

        return User::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function platformAdmins(): Collection
    {
        return $this->cache['platform'] ??= User::query()->where('is_platform_admin', true)->orderBy('id')->get();
    }
}
