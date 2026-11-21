<?php

namespace App\Http\Controllers;

use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\PromptPopulation;
use App\Models\Brand;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Onboarding\CostEstimator;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The brand's measurement plan: standard, inheritance and own market choices (docs/spec.md 7.6, T53, ADR 0042).
 * The settings page and the wizard (T55) and the estimate (T54) use the same shape.
 */
class BrandMeasurementPlanController extends Controller
{
    public function __construct(private MeasurementPlans $plans) {}

    public function show(Brand $brand): JsonResponse
    {
        Gate::authorize('view', $brand);

        return response()->json($this->plans->present($this->tenant(), $brand));
    }

    /** Saves the standard and the listed markets together; a partly invalid payload changes nothing. */
    public function update(Request $request, Brand $brand): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $brand);
        Gate::authorize('create', MarketEngine::class);

        $data = $request->validate($this->draftRules());
        $tenant = $this->tenant();
        $this->plans->save($tenant, $brand, $data);

        if ($request->header('X-Inertia') !== null) {
            return back();
        }

        return response()->json($this->plans->present($tenant, $brand->refresh()));
    }

    /** Preview the same draft as update(), optionally including a market still in the onboarding wizard. */
    public function estimate(Request $request, Brand $brand, CostEstimator $estimator): JsonResponse
    {
        Gate::authorize('update', $brand);
        Gate::authorize('create', MarketEngine::class);
        $data = $request->validate($this->draftRules() + [
            'new_market' => ['sometimes', 'required', 'array:plan_mode,engines,prompt_count,core_prompt_count'],
            'new_market.prompt_count' => ['required_with:new_market', 'integer', 'between:0,1000'],
            'new_market.core_prompt_count' => ['required_with:new_market', 'integer', 'min:0', 'lte:new_market.prompt_count'],
            'new_market.plan_mode' => ['required_with:new_market', Rule::in([Market::PLAN_INHERIT, Market::PLAN_OWN])],
        ]);
        $tenant = $this->tenant();
        $draft = $this->plans->draft($tenant, $brand, $data);
        $effective = $this->plans->preview($brand, $draft);
        $populations = PromptPopulation::forMarkets(array_keys($effective));
        $markets = [];
        foreach ($effective as $id => $settings) {
            $markets[] = $settings + ['id' => $id, 'prompts' => $populations[$id] ?? new PromptPopulation];
        }

        if (isset($data['new_market'])) {
            $new = $data['new_market'];
            $errors = [];
            if (array_key_exists('engines', $new)) {
                if ($new['plan_mode'] === Market::PLAN_INHERIT) {
                    throw ValidationException::withMessages(['new_market.engines' => __('app.plan.engines_with_inherit')]);
                }
                $engines = $new['engines'];
            } else {
                $engines = $draft->standard ?? $this->plans->rules($brand->planEngines()->get());
            }
            $engines = $this->plans->validateRules($tenant, $engines, 'new_market.engines', $errors);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $markets[] = ['id' => null, 'plan_mode' => $new['plan_mode'], 'is_active' => true, 'engines' => $engines,
                'prompts' => new PromptPopulation((int) $new['prompt_count'], (int) $new['core_prompt_count'])];
        }

        return response()->json($estimator->estimate($tenant, $markets));
    }

    /** @return array<string, list<string>> */
    private function draftRules(): array
    {
        return [
            'standard' => ['sometimes', 'array:engines'],
            'standard.engines' => ['array'],
            'markets' => ['sometimes', 'array'],
            'markets.*' => ['array:id,plan_mode,engines'],
        ];
    }

    private function tenant(): Team
    {
        return Team::query()->findOrFail((int) app(TenantContext::class)->require());
    }
}
