<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ReportLinkViewPolicy extends TenantResourcePolicy
{
    protected TenantPermission $view = TenantPermission::ManageReports;

    protected TenantPermission $manage = TenantPermission::ManageReports;

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
