<?php

namespace App\Http\Controllers;

use App\Brands\IdentityLibrary;
use App\Integrations\BrandIntegrations;
use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand settings (docs/spec.md 14.2, T28): name, aliases, domains, markets and integrations.
 * The measurement plan is saved through BrandMeasurementPlanController (docs/spec.md 7.6).
 * Integration config is never sent to the browser.
 */
class BrandSettingsController extends Controller
{
    public function __construct(private BrandIntegrations $integrations, private MeasurementPlans $plans) {}

    public function show(Brand $brand): Response
    {
        Gate::authorize('view', $brand);

        $brand->load([
            'aliases',
            'domains',
            'markets' => fn ($query) => $query->orderBy('id'),
            'integrations',
        ]);
        $seeIntegrations = Gate::allows('viewAny', Integration::class);

        return Inertia::render('Brands/Settings', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_domain' => $brand->primary_domain,
            ],
            'aliases' => $brand->aliases
                ->where('match_mode', '!=', 'regex')
                ->map(fn ($alias): array => [
                    'alias' => $alias->alias,
                    'match_mode' => $alias->match_mode,
                ])->values(),
            'domains' => $brand->domains->map(fn ($domain): array => [
                'domain' => $domain->domain,
                'include_subdomains' => $domain->include_subdomains,
            ])->values(),
            'markets' => $brand->markets->map(fn (Market $market): array => [
                'id' => $market->id,
                'country' => $market->country,
                'language' => $market->language,
                'location_label' => $market->location_label,
                'is_active' => $market->is_active,
                'plan_mode' => $market->plan_mode,
            ])->values(),
            // Standard plan, inheritance and own market choices (docs/spec.md 7.6).
            'measurementPlan' => $this->plans->present(Team::query()->findOrFail((int) app(TenantContext::class)->require()), $brand),
            'integrations' => $seeIntegrations
                ? $brand->integrations->map(fn (Integration $integration): array => [
                    'type' => $integration->type,
                    'status' => $integration->status,
                    'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
                ])->values()
                : [],
            'clickhouseEndpoints' => $seeIntegrations ? $this->integrations->endpoints() : [],
            'logUploadUrl' => $seeIntegrations ? route('bot-log-uploads.index', ['team' => current_tenant_id()]) : null,
            'can' => [
                'manage' => Gate::allows('update', $brand),
                'integrations' => $seeIntegrations,
            ],
        ]);
    }

    public function update(Request $request, Brand $brand, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'primary_domain' => ['sometimes', 'required', 'string', 'max:255', 'regex:'.IdentityLibrary::DOMAIN],
            'aliases' => ['sometimes', 'array', 'max:50'],
            'aliases.*.alias' => ['required', 'string', 'max:255'],
            'aliases.*.match_mode' => ['required', Rule::in(['exact_word', 'case_insensitive'])],
            'domains' => ['sometimes', 'array', 'max:50'],
            'domains.*.domain' => ['required', 'string', 'max:255', 'regex:'.IdentityLibrary::DOMAIN],
            'domains.*.include_subdomains' => ['required', 'boolean'],
            'markets' => ['sometimes', 'array'],
            'markets.*.id' => ['required', 'integer'],
            'markets.*.location_label' => ['nullable', 'string', 'max:255'],
            'markets.*.is_active' => ['required', 'boolean'],
            'engines' => ['prohibited'],
            ...$this->integrations->rules(),
        ], [], [
            'name' => __('app.workspace.name'),
            'primary_domain' => __('app.workspace.primary_domain'),
            'aliases' => __('app.workspace.aliases'),
            'aliases.*.alias' => __('app.workspace.alias'),
            'aliases.*.match_mode' => __('app.workspace.match_mode'),
            'domains' => __('app.workspace.domains'),
            'domains.*.domain' => __('app.workspace.domain'),
            'domains.*.include_subdomains' => __('app.workspace.include_subdomains'),
            'markets.*.location_label' => __('app.workspace.location'),
            'clickhouse_host' => __('app.workspace.clickhouse_host'),
            'clickhouse_database' => __('app.workspace.clickhouse_database'),
            'clickhouse_table' => __('app.workspace.clickhouse_table'),
            'clickhouse_user' => __('app.workspace.clickhouse_user'),
            'clickhouse_password' => __('app.workspace.clickhouse_password'),
            'ga4_property_id' => __('app.workspace.ga4_property'),
            'ga4_client_email' => __('app.workspace.ga4_email'),
            'ga4_private_key' => __('app.workspace.ga4_key'),
        ]);

        $identity->updateSettings($brand, $data);

        return back();
    }
}
