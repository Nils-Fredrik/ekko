<?php

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Actions\Jetstream\UpdateTeamName;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Http\Controllers\Jetstream\ApiTokenController;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole as JetstreamUpdateTeamMemberRole;
use Laravel\Jetstream\Http\Controllers\Inertia\ApiTokenController as JetstreamApiTokenController;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateTeam::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);

        // Role changes go through TeamPolicy::assignRole (partner advisor is restricted).
        $this->app->bind(JetstreamUpdateTeamMemberRole::class, UpdateTeamMemberRole::class);

        // A foreign token id gives 404 instead of a 500 (tests/Feature/TenantIsolation).
        $this->app->bind(JetstreamApiTokenController::class, ApiTokenController::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        // Tenant roles (docs/spec.md 5.2). Names and descriptions are translation keys.
        foreach (TenantRole::cases() as $role) {
            Jetstream::role(
                $role->value,
                $role->nameKey(),
                array_map(fn (TenantPermission $permission) => $permission->value, $role->permissions()),
            )->description($role->descriptionKey());
        }

        // API tokens only have read and write (docs/spec.md 15.1), separate from the role permissions.
        Jetstream::permissions(['read', 'write']);
        Jetstream::defaultApiTokenPermissions(['read']);
    }
}
