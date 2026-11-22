<?php

namespace App\Http\Controllers;

use App\Brands\IdentityLibrary;
use App\Integrations\BrandIntegrations;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The old combined form still accepts PUT until U27 removes it.
 * GET opens identity (docs/ux-arbeidsflate.md 6.1). Integration config is never sent to the browser.
 */
class BrandSettingsController extends Controller
{
    public function __construct(private BrandIntegrations $integrations) {}

    public function show(Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return to_route('brands.setup.identity', $brand);
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
