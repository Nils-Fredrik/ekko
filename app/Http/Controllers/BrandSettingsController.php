<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardLabels;
use App\Integrations\BrandIntegrations;
use App\MeasurementPlan\Frequency;
use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\EnginePrice;
use App\Models\Integration;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand settings (docs/spec.md 14.2, T28): name, aliases, domains, markets, engines and integrations.
 * Integration config is never sent to the browser.
 */
class BrandSettingsController extends Controller
{
    private const DOMAIN = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\z/i';

    public function __construct(private BrandIntegrations $integrations, private MeasurementPlans $plans) {}

    public function show(Brand $brand): Response
    {
        Gate::authorize('view', $brand);

        $brand->load(['aliases', 'domains', 'markets.marketEngines', 'integrations']);
        $engineKeys = $brand->markets
            ->flatMap(fn (Market $market) => $market->marketEngines->pluck('engine_key'))
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
        $engineNames = DashboardLabels::engines($engineKeys);
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
            'engines' => $brand->markets->flatMap(fn (Market $market) => $market->marketEngines->map(fn (MarketEngine $engine): array => [
                'id' => $engine->id,
                'market_id' => $market->id,
                'market' => $market->location_label !== '' && $market->location_label !== null
                    ? $market->location_label
                    : $market->country,
                'name' => $engineNames[$engine->engine_key] ?? $engine->engine_key,
                'frequency' => $engine->frequency,
                'prompt_scope' => $engine->prompt_scope,
                'repetitions' => $engine->repetitions,
            ]))->values(),
            // Standard plan, inheritance and own market choices (docs/spec.md 7.6, T53); the editor is T55.
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

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('update', $brand);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'primary_domain' => ['sometimes', 'required', 'string', 'max:255', 'regex:'.self::DOMAIN],
            'aliases' => ['sometimes', 'array', 'max:50'],
            'aliases.*.alias' => ['required', 'string', 'max:255'],
            'aliases.*.match_mode' => ['required', Rule::in(['exact_word', 'case_insensitive'])],
            'domains' => ['sometimes', 'array', 'max:50'],
            'domains.*.domain' => ['required', 'string', 'max:255', 'regex:'.self::DOMAIN],
            'domains.*.include_subdomains' => ['required', 'boolean'],
            'markets' => ['sometimes', 'array'],
            'markets.*.id' => ['required', 'integer'],
            'markets.*.location_label' => ['nullable', 'string', 'max:255'],
            'markets.*.is_active' => ['required', 'boolean'],
            'engines' => ['sometimes', 'array'],
            'engines.*.id' => ['required', 'integer'],
            'engines.*.frequency' => ['required', Rule::in(Frequency::schedulable())],
            'engines.*.prompt_scope' => ['required', Rule::in(['all', 'core'])],
            'engines.*.repetitions' => ['required', 'integer', 'between:1,3'],
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
            'engines.*.frequency' => __('app.workspace.frequency'),
            'engines.*.prompt_scope' => __('app.workspace.prompt_scope'),
            'engines.*.repetitions' => __('app.workspace.repetitions'),
            'clickhouse_host' => __('app.workspace.clickhouse_host'),
            'clickhouse_database' => __('app.workspace.clickhouse_database'),
            'clickhouse_table' => __('app.workspace.clickhouse_table'),
            'clickhouse_user' => __('app.workspace.clickhouse_user'),
            'clickhouse_password' => __('app.workspace.clickhouse_password'),
            'ga4_property_id' => __('app.workspace.ga4_property'),
            'ga4_client_email' => __('app.workspace.ga4_email'),
            'ga4_private_key' => __('app.workspace.ga4_key'),
        ]);

        DB::transaction(function () use ($brand, $data): void {
            $locked = Brand::query()->lockForUpdate()->findOrFail($brand->id);
            $this->saveIdentity($locked, $data);
            $this->saveAliases($locked, $data);
            $this->saveDomains($locked, $data);
            $this->saveMarkets($locked, $data);
            $this->saveEngines($locked, $data);
            $this->integrations->apply($locked, $data);
        });

        return back();
    }

    /** @param  array<string, mixed>  $data */
    private function saveIdentity(Brand $brand, array $data): void
    {
        $changes = [];
        if (array_key_exists('name', $data) && is_string($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('primary_domain', $data) && is_string($data['primary_domain'])) {
            $changes['primary_domain'] = strtolower($data['primary_domain']);
        }
        if ($changes !== []) {
            $brand->fill($changes)->save();
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveAliases(Brand $brand, array $data): void
    {
        if (! array_key_exists('aliases', $data) || ! is_array($data['aliases'])) {
            return;
        }

        $seen = [];
        foreach ($data['aliases'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $alias = is_string($row['alias'] ?? null) ? trim($row['alias']) : '';
            $mode = is_string($row['match_mode'] ?? null) ? $row['match_mode'] : '';
            $key = mb_strtolower($alias).'|'.$mode;
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "aliases.{$index}.alias" => __('app.workspace.duplicate'),
                ]);
            }
            $seen[$key] = true;
            $data['aliases'][$index]['alias'] = $alias;
        }

        $brand->aliases()->where('match_mode', '!=', 'regex')->delete();
        foreach ($data['aliases'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $brand->aliases()->create([
                'alias' => $row['alias'],
                'match_mode' => $row['match_mode'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveDomains(Brand $brand, array $data): void
    {
        if (! array_key_exists('domains', $data) || ! is_array($data['domains'])) {
            return;
        }

        $seen = [];
        foreach ($data['domains'] as $index => $row) {
            if (! is_array($row) || ! is_string($row['domain'] ?? null)) {
                continue;
            }
            $domain = strtolower($row['domain']);
            if (isset($seen[$domain])) {
                throw ValidationException::withMessages([
                    "domains.{$index}.domain" => __('app.workspace.duplicate'),
                ]);
            }
            $seen[$domain] = true;
            $data['domains'][$index]['domain'] = $domain;
        }

        $brand->domains()->delete();
        foreach ($data['domains'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $brand->domains()->create([
                'domain' => $row['domain'],
                'include_subdomains' => (bool) ($row['include_subdomains'] ?? false),
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveMarkets(Brand $brand, array $data): void
    {
        if (! array_key_exists('markets', $data) || ! is_array($data['markets'])) {
            return;
        }

        $markets = $brand->markets()->get()->keyBy('id');
        foreach ($data['markets'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $market = $markets->get($row['id'] ?? null);
            if (! $market instanceof Market) {
                throw ValidationException::withMessages([
                    "markets.{$index}.id" => __('app.workspace.unknown_market'),
                ]);
            }
            Gate::authorize('update', $market);
            $label = $row['location_label'] ?? null;
            $market->fill([
                'location_label' => is_string($label) && $label !== '' ? $label : null,
                'is_active' => (bool) $row['is_active'],
            ])->save();
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveEngines(Brand $brand, array $data): void
    {
        if (! array_key_exists('engines', $data) || ! is_array($data['engines'])) {
            return;
        }

        $ids = $brand->markets()->pluck('id');
        $engines = MarketEngine::query()->whereIn('market_id', $ids)->get()->keyBy('id');
        $this->guardEngineScopes($engines, $data['engines']);

        // A scope change is removed before it is written back. Two rows can then trade all and core
        // without the unique key seeing both of them on the same scope in between.
        $moves = [];
        $detached = [];
        foreach ($data['engines'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $engine = $engines->get($row['id'] ?? null);
            if (! $engine instanceof MarketEngine) {
                throw ValidationException::withMessages([
                    "engines.{$index}.id" => __('app.workspace.unknown_engine'),
                ]);
            }
            Gate::authorize('update', $engine);
            $changed = $engine->frequency !== $row['frequency'] || $engine->prompt_scope !== $row['prompt_scope'] || $engine->repetitions !== (int) $row['repetitions'];
            if ($changed && ! isset($detached[$engine->market_id])) {
                // Changing a row of an inheriting market gives it own choices from its current setup (docs/spec.md 7.6).
                // The form sends every row on each save, so unchanged rows leave inheritance alone.
                $this->plans->detach(Market::query()->findOrFail($engine->market_id));
                $detached[$engine->market_id] = true;
            }
            if ($row['frequency'] !== 'off' && ! EnginePrice::query()->where('engine_key', $engine->engine_key)->where('valid_from', '<=', now())->exists()) {
                throw ValidationException::withMessages([
                    "engines.{$index}.frequency" => __('onboarding.issues.no_price', ['engine' => DashboardLabels::engines([$engine->engine_key])[$engine->engine_key]]),
                ]);
            }
            if ($engine->prompt_scope !== $row['prompt_scope']) {
                $moves[] = [$engine, $row];

                continue;
            }
            $engine->fill([
                'frequency' => $row['frequency'],
                'prompt_scope' => $row['prompt_scope'],
                'repetitions' => $row['repetitions'],
            ])->save();
        }

        foreach ($moves as [$engine]) {
            $engine->delete();
        }
        foreach ($moves as [$engine, $row]) {
            $replacement = new MarketEngine;
            $replacement->forceFill([
                'id' => $engine->id,
                'market_id' => $engine->market_id,
                'engine_key' => $engine->engine_key,
                'frequency' => $row['frequency'],
                'prompt_scope' => $row['prompt_scope'],
                'repetitions' => $row['repetitions'],
                'created_at' => $engine->created_at,
            ])->save();
        }
    }

    /**
     * The same market and engine can have all and core, but not two rows with one scope.
     * A conflicting choice is rejected before anything is written.
     *
     * @param  Collection<int, MarketEngine>  $engines
     * @param  array<mixed>  $rows
     */
    private function guardEngineScopes(Collection $engines, array $rows): void
    {
        $final = [];
        $submitted = [];
        $messages = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $engine = $engines->get($row['id'] ?? null);
            if (! $engine instanceof MarketEngine) {
                continue;
            }
            $scope = is_string($row['prompt_scope'] ?? null) ? $row['prompt_scope'] : '';
            $key = $engine->market_id.'|'.$engine->engine_key.'|'.$scope;
            if (isset($final[$key])) {
                $messages["engines.{$index}.prompt_scope"] = __('app.workspace.scope_conflict');
            }
            $final[$key] = $index;
            $submitted[$engine->id] = true;
        }
        foreach ($engines as $engine) {
            if (isset($submitted[$engine->id])) {
                continue;
            }
            $key = $engine->market_id.'|'.$engine->engine_key.'|'.$engine->prompt_scope;
            if (isset($final[$key])) {
                $messages['engines.'.$final[$key].'.prompt_scope'] = __('app.workspace.scope_conflict');
            }
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
