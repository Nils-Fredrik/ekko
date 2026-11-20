<?php

namespace App\MeasurementPlan;

use App\Dashboard\DashboardLabels;
use App\Models\Brand;
use App\Models\BrandPlanEngine;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The brand's measurement plan with inheritance and explicit own market choices (docs/spec.md 6.2 and 7.6, ADR 0042).
 *
 * The brand owns a standard plan (brand_plan_engines). A market either inherits it or has own choices
 * (markets.plan_mode). market_engines always holds the market's effective rules: an inheriting market holds an
 * exact copy of the standard, kept in step in the same transaction as every change to the standard or the mode.
 * The scheduler, first run, Anthropic planner, estimate, engine filters and list_brands therefore read one source.
 *
 * Per engine a plan has at most one rule per prompt scope. A core prompt uses the engine's core rule when it has
 * one and otherwise its all rule; a standard prompt uses only the all rule (ruleFor). So every prompt gets at most
 * one effective rule per engine.
 *
 * @phpstan-type PlanRule array{engine_key: string, prompt_scope: string, frequency: string, repetitions: int}
 */
final class MeasurementPlans
{
    public const SCOPES = ['all', 'core'];

    /**
     * The one rule that applies to a prompt on one engine, from that engine's rules in a market.
     *
     * @param  iterable<MarketEngine|PlanRule>  $rules
     * @return MarketEngine|PlanRule|null
     */
    public static function ruleFor(iterable $rules, bool $corePrompt): MarketEngine|array|null
    {
        $byScope = [];
        foreach ($rules as $rule) {
            $byScope[$rule['prompt_scope']] = $rule;
        }

        return ($corePrompt ? ($byScope['core'] ?? null) : null) ?? $byScope['all'] ?? null;
    }

    /**
     * The plan as the settings page, the wizard and the estimate see it: the standard, every market's mode and
     * effective rules, and the choices the package allows.
     *
     * @return array{
     *     brand_id: int,
     *     standard: list<PlanRule>,
     *     markets: list<array{id: int, country: string, language: string, location_label: string|null, is_active: bool, plan_mode: string, engines: list<PlanRule>}>,
     *     options: array<string, mixed>
     * }
     */
    public function present(Team $tenant, Brand $brand): array
    {
        $brand->load([
            'planEngines' => fn ($query) => $query->orderBy('engine_key')->orderBy('prompt_scope'),
            'markets' => fn ($query) => $query->orderBy('id'),
            'markets.marketEngines' => fn ($query) => $query->orderBy('engine_key')->orderBy('prompt_scope'),
        ]);

        return [
            'brand_id' => $brand->id,
            'standard' => $this->rules($brand->planEngines),
            'markets' => $brand->markets->map(fn (Market $market): array => [
                'id' => $market->id,
                'country' => $market->country,
                'language' => $market->language,
                'location_label' => $market->location_label,
                'is_active' => (bool) $market->is_active,
                'plan_mode' => $market->plan_mode,
                'engines' => $this->rules($market->marketEngines),
            ])->values()->all(),
            'options' => $this->options($tenant),
        ];
    }

    /**
     * What the package allows, for the plan editor in settings and the wizard.
     *
     * @return array{frequencies: list<string>, scopes: list<string>, max_repetitions: int, engines: list<array<string, mixed>>, defaults: list<PlanRule>}
     */
    public function options(Team $tenant): array
    {
        $plan = $this->package($tenant);
        $priced = EnginePrice::query()->where('valid_from', '<=', now())->distinct()->pluck('engine_key')->all();
        $engines = Engine::query()->orderBy('key')->get();
        $labels = DashboardLabels::engines($engines->pluck('key')->all());

        return [
            'frequencies' => Frequency::schedulable(),
            'scopes' => self::SCOPES,
            'max_repetitions' => $plan instanceof Plan ? max(1, min(3, $plan->max_repetitions)) : 1,
            'engines' => $engines->map(fn (Engine $engine): array => [
                'engine_key' => $engine->key,
                'name' => $labels[$engine->key] ?? $engine->key,
                'selectable' => $this->selectable($tenant, $plan, $engine),
                'max_frequency' => $this->selectable($tenant, $plan, $engine) && $plan instanceof Plan ? $plan->maxFrequencyForEngine($engine->key) : 'off',
                'has_price' => in_array($engine->key, $priced, true),
            ])->values()->all(),
            'defaults' => StandardPlan::forTenant($tenant),
        ];
    }

    /**
     * Validates a proposed change without writing anything. Every error in the payload is reported at once.
     *
     * Payload: standard?: {engines: list<rule>}, markets?: list<{id, plan_mode: inherit|own, engines?: list<rule>}>.
     * A market not listed keeps its mode and rules. own without engines keeps the market's current effective rules
     * (a copy of the standard when it inherited). inherit takes no engines.
     *
     * @param  array<string, mixed>  $input
     */
    public function draft(Team $tenant, Brand $brand, array $input): PlanDraft
    {
        $errors = [];
        $standard = null;
        if (array_key_exists('standard', $input)) {
            $rows = is_array($input['standard']) ? ($input['standard']['engines'] ?? null) : null;
            $standard = $this->validateRules($tenant, $rows, 'standard.engines', $errors);
        }
        $markets = [];
        if (array_key_exists('markets', $input)) {
            if (! is_array($input['markets']) || ! array_is_list($input['markets'])) {
                $errors['markets'] = __('app.plan.invalid');
            } else {
                $known = Market::query()->where('brand_id', $brand->id)->pluck('id')->all();
                foreach ($input['markets'] as $index => $row) {
                    $id = is_array($row) ? self::integer($row['id'] ?? null) : null;
                    if ($id === null || ! in_array($id, $known, true)) {
                        $errors["markets.{$index}.id"] = __('app.workspace.unknown_market');

                        continue;
                    }
                    if (isset($markets[$id])) {
                        $errors["markets.{$index}.id"] = __('app.plan.duplicate_market');

                        continue;
                    }
                    $mode = $row['plan_mode'] ?? null;
                    if (! in_array($mode, [Market::PLAN_INHERIT, Market::PLAN_OWN], true)) {
                        $errors["markets.{$index}.plan_mode"] = __('app.plan.invalid_mode');

                        continue;
                    }
                    $rules = null;
                    if (array_key_exists('engines', $row)) {
                        if ($mode === Market::PLAN_INHERIT) {
                            $errors["markets.{$index}.engines"] = __('app.plan.engines_with_inherit');

                            continue;
                        }
                        $rules = $this->validateRules($tenant, $row['engines'], "markets.{$index}.engines", $errors);
                    }
                    $markets[$id] = ['plan_mode' => $mode, 'engines' => $rules];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new PlanDraft($standard, $markets);
    }

    /**
     * The effective rules every market of the brand would have after the draft, without writing anything.
     * Inactive markets are included and marked; they contribute no runs.
     *
     * @return array<int, array{plan_mode: string, is_active: bool, engines: list<PlanRule>}>
     */
    public function preview(Brand $brand, PlanDraft $draft): array
    {
        $standard = $draft->standard ?? $this->rules($brand->planEngines()->get());
        $result = [];
        foreach ($brand->markets()->with('marketEngines')->orderBy('id')->get() as $market) {
            $change = $draft->markets[$market->id] ?? null;
            $mode = $change['plan_mode'] ?? $market->plan_mode;
            $rules = match (true) {
                $mode === Market::PLAN_INHERIT => $standard,
                $change !== null && $change['engines'] !== null => $change['engines'],
                // own without engines keeps the current effective rules, also when it inherited until now.
                default => $this->rules($market->marketEngines),
            };
            $result[$market->id] = ['plan_mode' => $mode, 'is_active' => (bool) $market->is_active, 'engines' => $this->sorted($rules)];
        }

        return $result;
    }

    /**
     * Validates and saves the change in one transaction under the brand's lock. A partly invalid payload writes nothing.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Team $tenant, Brand $brand, array $input): void
    {
        DB::transaction(function () use ($tenant, $brand, $input): void {
            $locked = Brand::query()->lockForUpdate()->findOrFail($brand->id);
            $draft = $this->draft($tenant, $locked, $input);
            $this->apply($locked, $draft);
        });
    }

    /**
     * Writes a validated draft. Callers hold the brand's lock in a transaction.
     */
    public function apply(Brand $brand, PlanDraft $draft): void
    {
        if ($draft->standard !== null) {
            $this->sync($brand->planEngines(), $draft->standard);
        }
        foreach ($draft->markets as $id => $change) {
            $market = Market::query()->where('brand_id', $brand->id)->findOrFail($id);
            $market->forceFill(['plan_mode' => $change['plan_mode']])->save();
            if ($change['plan_mode'] === Market::PLAN_OWN && $change['engines'] !== null) {
                $this->sync($market->marketEngines(), $change['engines']);
            }
        }
        $this->refreshInheriting($brand);
    }

    /**
     * Sets the standard of a new brand (wizard). Its markets inherit through inherit().
     *
     * @param  list<PlanRule>  $rules
     */
    public function initialize(Brand $brand, array $rules): void
    {
        $this->sync($brand->planEngines(), $rules);
    }

    /** A new market inherits the brand's plan (docs/spec.md 7.6). */
    public function inherit(Market $market): void
    {
        $market->forceFill(['plan_mode' => Market::PLAN_INHERIT])->save();
        $this->sync($market->marketEngines(), $this->rules(BrandPlanEngine::query()->where('brand_id', $market->brand_id)->get()));
    }

    /**
     * A market with explicit own rules (wizard for a new market with its own choices).
     *
     * @param  list<PlanRule>  $rules
     */
    public function own(Market $market, array $rules): void
    {
        $market->forceFill(['plan_mode' => Market::PLAN_OWN])->save();
        $this->sync($market->marketEngines(), $rules);
    }

    /**
     * A direct edit of a market's rules (the per-row engine editor in brand settings) makes them the market's own
     * choices, starting from its current setup (docs/spec.md 7.6).
     */
    public function detach(Market $market): void
    {
        if ($market->plan_mode !== Market::PLAN_OWN) {
            $market->forceFill(['plan_mode' => Market::PLAN_OWN])->save();
        }
    }

    /**
     * Validates rule rows for the tenant's package. Off is allowed for every catalogue engine; an active rule needs an
     * available engine in the package, a frequency and repetitions within its caps and a current price.
     *
     * @param  array<string, array<mixed>|string>  $errors  messages by field, as ValidationException::withMessages takes them
     * @return list<PlanRule>
     */
    public function validateRules(Team $tenant, mixed $rows, string $prefix, array &$errors): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $errors[$prefix] = __('app.plan.invalid');

            return [];
        }
        if (count($rows) > (int) config('ekko.measurement_plan.max_rules')) {
            $errors[$prefix] = __('app.plan.too_many_rules', ['max' => (int) config('ekko.measurement_plan.max_rules')]);

            return [];
        }
        $plan = $this->package($tenant);
        $keys = array_values(array_filter(array_map(fn (mixed $row): mixed => is_array($row) ? ($row['engine_key'] ?? null) : null, $rows), 'is_string'));
        $engines = Engine::query()->whereIn('key', $keys)->get()->keyBy('key');
        $priced = EnginePrice::query()->whereIn('engine_key', $keys)->where('valid_from', '<=', now())->distinct()->pluck('engine_key')->all();
        $frequencies = Frequency::schedulable();
        $seen = [];
        $rules = [];
        foreach ($rows as $index => $row) {
            $at = "{$prefix}.{$index}";
            $key = is_array($row) ? ($row['engine_key'] ?? null) : null;
            $engine = is_string($key) ? $engines->get($key) : null;
            if (! is_array($row) || ! $engine instanceof Engine) {
                $errors["{$at}.engine_key"] = __('app.plan.unknown_engine');

                continue;
            }
            $scope = $row['prompt_scope'] ?? 'all';
            $frequency = $row['frequency'] ?? null;
            $repetitions = self::integer($row['repetitions'] ?? 1);
            if (! in_array($scope, self::SCOPES, true)) {
                $errors["{$at}.prompt_scope"] = __('app.plan.invalid_scope');

                continue;
            }
            if (! is_string($frequency) || ! in_array($frequency, $frequencies, true)) {
                $errors["{$at}.frequency"] = __('app.plan.invalid_frequency');

                continue;
            }
            if (! is_int($repetitions) || $repetitions < 1 || $repetitions > 3) {
                $errors["{$at}.repetitions"] = __('app.plan.invalid_repetitions');

                continue;
            }
            if (isset($seen[$engine->key.'|'.$scope])) {
                $errors["{$at}.prompt_scope"] = __('app.workspace.scope_conflict');

                continue;
            }
            $seen[$engine->key.'|'.$scope] = true;
            $name = DashboardLabels::engines([$engine->key])[$engine->key];
            if ($frequency !== 'off') {
                if (! $this->selectable($tenant, $plan, $engine)) {
                    $errors["{$at}.engine_key"] = __('app.plan.engine_unavailable', ['engine' => $name]);

                    continue;
                }
                /** @var Plan $plan selectable() requires a package */
                if (! Frequency::allows($frequency, $plan->maxFrequencyForEngine($engine->key))) {
                    $errors["{$at}.frequency"] = __('app.plan.frequency_above_cap', ['engine' => $name]);

                    continue;
                }
                if ($repetitions > $plan->max_repetitions) {
                    $errors["{$at}.repetitions"] = __('app.plan.repetitions_above_cap', ['max' => $plan->max_repetitions]);

                    continue;
                }
                if (! in_array($engine->key, $priced, true)) {
                    $errors["{$at}.engine_key"] = __('onboarding.issues.no_price', ['engine' => $name]);

                    continue;
                }
            }
            $rules[] = ['engine_key' => $engine->key, 'prompt_scope' => $scope, 'frequency' => $frequency, 'repetitions' => $repetitions];
        }

        return $this->sorted($rules);
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1 ? (int) $value : null;
    }

    private function selectable(Team $tenant, ?Plan $plan, Engine $engine): bool
    {
        return $plan instanceof Plan && $engine->isAvailable() && $plan->allowsEngine($engine->key)
            && ($tenant->kind !== 'pitch' || in_array($engine->key, StandardPlan::PITCH_ENGINES, true));
    }

    private function package(Team $tenant): ?Plan
    {
        return $tenant->plan_id === null ? null : Plan::query()->find($tenant->plan_id);
    }

    /** Copies the standard to every market of the brand that inherits it. */
    private function refreshInheriting(Brand $brand): void
    {
        $standard = $this->rules($brand->planEngines()->get());
        foreach (Market::query()->where('brand_id', $brand->id)->where('plan_mode', Market::PLAN_INHERIT)->orderBy('id')->get() as $market) {
            $this->sync($market->marketEngines(), $standard);
        }
    }

    /**
     * Makes the relation hold exactly these rules. Rows are matched on engine and scope, so unchanged rules keep
     * their ID and timestamps.
     *
     * @param  HasMany<BrandPlanEngine, Brand>|HasMany<MarketEngine, Market>  $relation
     * @param  list<PlanRule>  $rules
     */
    private function sync($relation, array $rules): void
    {
        $wanted = [];
        foreach ($rules as $rule) {
            $wanted[$rule['engine_key'].'|'.$rule['prompt_scope']] = $rule;
        }
        foreach ($relation->get() as $row) {
            $key = $row->engine_key.'|'.$row->prompt_scope;
            if (! isset($wanted[$key])) {
                $row->delete();

                continue;
            }
            $row->fill(['frequency' => $wanted[$key]['frequency'], 'repetitions' => $wanted[$key]['repetitions']]);
            if ($row->isDirty()) {
                $row->save();
            }
            unset($wanted[$key]);
        }
        foreach ($wanted as $rule) {
            $relation->create($rule);
        }
    }

    /**
     * @param  iterable<BrandPlanEngine|MarketEngine>  $rows
     * @return list<PlanRule>
     */
    private function rules(iterable $rows): array
    {
        $rules = [];
        foreach ($rows as $row) {
            $rules[] = ['engine_key' => $row->engine_key, 'prompt_scope' => $row->prompt_scope, 'frequency' => $row->frequency, 'repetitions' => (int) $row->repetitions];
        }

        return $this->sorted($rules);
    }

    /**
     * @param  list<PlanRule>  $rules
     * @return list<PlanRule>
     */
    private function sorted(array $rules): array
    {
        usort($rules, fn (array $a, array $b): int => [$a['engine_key'], $a['prompt_scope']] <=> [$b['engine_key'], $b['prompt_scope']]);

        return $rules;
    }
}
