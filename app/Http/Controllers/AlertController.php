<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alerts and the rules behind them (docs/spec.md 11.1 and 14.2, T28).
 */
class AlertController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Alert::class);

        $alerts = Alert::query()->with('alertRule.brand')->latest('triggered_at')->limit(50)->get();
        $rules = AlertRule::query()->with('brand')->orderBy('id')->get();

        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'type' => $alert->alertRule->type,
                'brand' => $alert->alertRule->brand?->name,
                'subject' => $alert->subject,
                'triggered_at' => $alert->triggered_at->toIso8601String(),
                'acknowledged' => $alert->acknowledged_at !== null,
            ])->values(),
            'rules' => $rules->map(fn (AlertRule $rule): array => [
                'id' => $rule->id,
                'brand_id' => $rule->brand_id,
                'brand' => $rule->brand?->name,
                'type' => $rule->type,
                'threshold' => $rule->threshold,
                'is_active' => $rule->is_active,
            ])->values(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => Gate::allows('create', AlertRule::class),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        Gate::authorize('create', AlertRule::class);
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'type' => ['required', Rule::in([
                'visibility_drop', 'competitor_takeover', 'citation_lost', 'new_competitor', 'model_change', 'quota', 'integration_error',
            ])],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'brand_id' => __('app.workspace.brand'),
            'threshold' => __('app.workspace.threshold'),
        ]);

        $brand = Brand::query()->findOrFail($data['brand_id']);
        $brand->alertRules()->create([
            'type' => $data['type'],
            'threshold' => $data['threshold'] ?? null,
            'channels' => ['in_app'],
            'is_active' => $data['is_active'],
        ]);

        return back();
    }

    public function updateRule(Request $request, AlertRule $alertRule): RedirectResponse
    {
        Gate::authorize('update', $alertRule);
        $data = $request->validate([
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'brand_id' => __('app.workspace.brand'),
            'threshold' => __('app.workspace.threshold'),
        ]);

        $alertRule->fill([
            'threshold' => $data['threshold'] ?? null,
            'is_active' => $data['is_active'],
        ])->save();

        return back();
    }

    public function acknowledge(Alert $alert): RedirectResponse
    {
        Gate::authorize('update', $alert);

        // The first receipt stands: acknowledging again does not change who or when.
        if ($alert->acknowledged_at === null) {
            $alert->forceFill([
                'acknowledged_by' => request()->user()?->id,
                'acknowledged_at' => now(),
            ])->save();
        }

        return back();
    }
}
