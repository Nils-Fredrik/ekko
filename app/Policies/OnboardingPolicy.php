<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\Team;
use App\Models\User;
use App\Onboarding\Analysis;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;

/**
 * The onboarding wizard's proposals (docs/spec.md 13, ADR 0018, ADR 0009). An analysis is not a model but
 * the proposal for a new brand or a new market, so the rights follow what it would create: a brand needs
 * `brands.manage`, a market needs `markets.manage` (tenant admin). The cost estimate belongs to the market
 * setup (`markets.manage`). A proposal is only readable in the tenant it was made in.
 */
class OnboardingPolicy
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantContext $context,
    ) {}

    public function createBrandAnalysis(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, TenantPermission::ManageBrands);
    }

    public function createMarketAnalysis(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, TenantPermission::ManageMarkets);
    }

    public function estimate(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, TenantPermission::ManageMarkets);
    }

    public function confirm(User $user, Analysis $analysis): bool
    {
        return $this->view($user, $analysis);
    }

    public function view(User $user, Analysis $analysis): bool
    {
        if ($this->context->id() !== $analysis->tenantId) {
            return false;
        }

        return $this->allowedInCurrentTenant($user, $analysis->kind === Analysis::BRAND ? TenantPermission::ManageBrands : TenantPermission::ManageMarkets);
    }

    private function allowedInCurrentTenant(User $user, TenantPermission $permission): bool
    {
        $tenantId = $this->context->id();
        $tenant = $tenantId === null ? null : Team::query()->find($tenantId);

        return $tenant !== null && $this->access->can($user, $tenant, $permission);
    }
}
