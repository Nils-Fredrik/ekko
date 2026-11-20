<?php

namespace App\Http\Controllers;

use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

        $data = $request->validate([
            'standard' => ['sometimes', 'array:engines'],
            'standard.engines' => ['array'],
            'markets' => ['sometimes', 'array'],
            'markets.*' => ['array:id,plan_mode,engines'],
        ]);
        $tenant = $this->tenant();
        $this->plans->save($tenant, $brand, $data);

        if ($request->header('X-Inertia') !== null) {
            return back();
        }

        return response()->json($this->plans->present($tenant, $brand->refresh()));
    }

    private function tenant(): Team
    {
        return Team::query()->findOrFail((int) app(TenantContext::class)->require());
    }
}
