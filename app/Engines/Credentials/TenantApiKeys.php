<?php

namespace App\Engines\Credentials;

use App\Models\Engine;
use App\Models\Team;
use App\Models\TenantApiKey;
use App\Models\User;
use App\Tenancy\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Spatie\Activitylog\Support\CauserResolver;

final readonly class TenantApiKeys
{
    public function __construct(
        private ApiKeyVerifier $verifier,
        private AuditLog $audit,
        private CauserResolver $causers,
    ) {}

    public function store(User $actor, ApiKeyProvider $provider, #[SensitiveParameter] string $key): TenantApiKey
    {
        Gate::forUser($actor)->authorize('create', TenantApiKey::class);
        $this->requireEnabled(Team::query()->findOrFail(current_tenant_id()));

        $key = trim($key);
        $this->verifier->verify($provider, $key);

        return DB::transaction(function () use ($actor, $provider, $key): TenantApiKey {
            $tenant = Team::query()->lockForUpdate()->findOrFail(current_tenant_id());
            Gate::forUser($actor)->authorize('create', TenantApiKey::class);
            $this->requireEnabled($tenant);

            return $this->causers->withCauser($actor, fn () => TenantApiKey::query()->updateOrCreate(
                ['provider' => $provider->value],
                ['key' => $key, 'last_verified_at' => now()],
            ));
        });
    }

    public function setBudget(User $actor, ?string $budgetUsd): void
    {
        $tenant = Team::query()->findOrFail(current_tenant_id());
        Gate::forUser($actor)->authorize('update', $tenant);
        $this->requireEnabled($tenant);

        if ($budgetUsd !== null && preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $budgetUsd) !== 1) {
            throw ValidationException::withMessages(['budget_usd' => __('usage.invalid_budget')]);
        }

        DB::transaction(function () use ($actor, $budgetUsd): void {
            $tenant = Team::query()->lockForUpdate()->findOrFail(current_tenant_id());
            Gate::forUser($actor)->authorize('update', $tenant);
            $this->requireEnabled($tenant);
            $previous = $tenant->monthly_byok_budget_usd;
            $tenant->monthly_byok_budget_usd = $budgetUsd;

            if ($previous !== $tenant->monthly_byok_budget_usd) {
                $tenant->save();
                $this->audit->byokBudgetChanged($actor, $tenant, $previous);
            }
        });
    }

    public function forEngine(Team $tenant, Engine $engine): ?TenantApiKey
    {
        if ($tenant->id !== current_tenant_id()) {
            throw new AuthorizationException;
        }

        $provider = ApiKeyProvider::tryFrom($engine->provider);

        return $tenant->byok_enabled && $engine->method === 'api' && $provider !== null
            ? TenantApiKey::query()->where('provider', $provider->value)->first()
            : null;
    }

    private function requireEnabled(Team $tenant): void
    {
        if (! $tenant->byok_enabled) {
            throw new AuthorizationException(__('usage.byok_disabled'));
        }
    }
}
