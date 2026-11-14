<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for tenant-owned resources (models with BelongsToTenant). A subclass names the
 * permission for reading and for changing:
 *
 *     class PromptPolicy extends TenantResourcePolicy
 *     {
 *         protected TenantPermission $manage = TenantPermission::ManagePrompts;
 *     }
 *
 * Reading needs $view (default ViewData) in the model's tenant, changing needs $manage. viewAny
 * and create are checked in the current tenant. The tenant scope and RLS already limit what can
 * be loaded; the policy decides what the role may do with it.
 */
abstract class TenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ViewData;

    protected TenantPermission $manage;

    public function __construct(
        protected readonly TenantAccess $access,
        protected readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, $this->view);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowedInTenantOf($user, $model, $this->view);
    }

    public function create(User $user): bool
    {
        return $this->allowedInCurrentTenant($user, $this->manage);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowedInTenantOf($user, $model, $this->manage);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowedInTenantOf($user, $model, $this->manage);
    }

    protected function allowedInCurrentTenant(User $user, TenantPermission $permission): bool
    {
        $tenantId = $this->context->id();
        $tenant = $tenantId === null ? null : Team::query()->find($tenantId);

        return $tenant !== null && $this->access->can($user, $tenant, $permission);
    }

    protected function allowedInTenantOf(User $user, Model $model, TenantPermission $permission): bool
    {
        $tenantId = $model->getAttribute('tenant_id');
        $tenant = $tenantId === null ? null : Team::query()->find((int) $tenantId);

        return $tenant !== null && $this->access->can($user, $tenant, $permission);
    }
}
