<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A user's own analysis preferences, including when the user only has read access to the brand. */
final class SavedViewPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ViewData;

    public function view(User $user, Model $model): bool
    {
        return (int) $model->getAttribute('user_id') === $user->id && parent::view($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }
}
