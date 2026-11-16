<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        // Only the platform admin deletes tenants (docs/spec.md 5.2). Deleting the account would
        // delete every tenant the user owns, so it is refused while the user owns a tenant.
        if ($user->ownedTeams()->where('personal_team', false)->exists()) {
            // Default error bag: DeleteUserForm.vue reads form.errors.password.
            throw ValidationException::withMessages([
                'password' => __('app.account.owns_tenants'),
            ]);
        }

        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        // Only personal teams are left here (tenants are refused above). They have no tenant data and are deleted
        // at once; DeletesTeams closes a tenant for 30 days instead (T39-F6).
        $user->ownedTeams->each(fn (Team $team) => $team->purge());
    }
}
