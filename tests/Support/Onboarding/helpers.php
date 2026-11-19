<?php

// Helpers shared by the wizard's tests (T25). Required from the test files, since Pest loads one file at a time
// when a single file is run.

use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

if (! function_exists('wizardTenant')) {
    /** @return array{0: Team, 1: User} a tenant and a user with the role in it, who has it as the current tenant */
    function wizardTenant(?Plan $plan = null, string $role = 'owner'): array
    {
        $tenant = Team::factory()->tenant()->create(['plan_id' => $plan?->id]);
        $user = $tenant->owner;
        if ($role !== 'owner') {
            $user = User::factory()->withPersonalTeam()->create();
            $tenant->users()->attach($user, ['role' => $role]);
        }
        $user->switchTeam($tenant);

        return [$tenant, $user->fresh()];
    }
}

if (! function_exists('inTenant')) {
    function inTenant(Team $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
    }
}

if (! function_exists('configurationRowCount')) {
    /** The rows the wizard would create when the user confirms. None of them may exist before. */
    function configurationRowCount(): int
    {
        return collect(['brands', 'brand_aliases', 'brand_domains', 'competitors', 'competitor_aliases', 'competitor_domains', 'markets', 'market_engines', 'topics', 'prompts', 'brand_facts'])
            ->sum(fn (string $table): int => DB::table($table)->count());
    }
}

if (! function_exists('startBrandAnalysis')) {
    /** @param  array<string, mixed>  $overrides */
    function startBrandAnalysis(array $overrides = []): array
    {
        return $overrides + ['name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'no', 'language' => 'NB'];
    }
}
