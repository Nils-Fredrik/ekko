<?php

namespace App\Http\Controllers;

use App\Alerts\AlertType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $type = AlertType::tryFrom((string) $request->input('type'));
        $tenantWide = $type?->isTenantWide() ?? false;
        $data = $request->validate([
            'brand_id' => [Rule::requiredIf(! $tenantWide), 'nullable', 'integer'],
            'type' => ['required', Rule::enum(AlertType::class)],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'brand_id' => __('app.workspace.brand'),
            'type' => __('app.workspace.type'),
            'threshold' => __('app.workspace.threshold'),
            'is_active' => __('app.workspace.active'),
        ]);

        // Quota and model changes belong to the tenant. A brand id is still checked when one is
        // sent, so a foreign brand cannot be used to slip past the tenant scope.
        $brand = null;
        if (isset($data['brand_id'])) {
            $brand = Brand::query()->findOrFail($data['brand_id']);
        }
        $brandId = $tenantWide ? null : $brand?->id;
        $exists = AlertRule::query()
            ->where('type', $data['type'])
            ->when($brandId === null, fn ($query) => $query->whereNull('brand_id'), fn ($query) => $query->where('brand_id', $brandId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'type' => __('app.workspace.rule_exists'),
            ]);
        }

        $attributes = [
            'type' => $data['type'],
            'threshold' => $this->threshold($type, $data['threshold'] ?? null),
            'channels' => ['in_app'],
            'is_active' => $data['is_active'],
        ];
        if ($tenantWide) {
            AlertRule::query()->create($attributes);
        } elseif ($brand instanceof Brand) {
            $brand->alertRules()->create($attributes);
        } else {
            throw ValidationException::withMessages([
                'brand_id' => __('validation.required', ['attribute' => __('app.workspace.brand')]),
            ]);
        }

        return back();
    }

    public function updateRule(Request $request, AlertRule $alertRule): RedirectResponse
    {
        Gate::authorize('update', $alertRule);
        $data = $request->validate([
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'threshold' => __('app.workspace.threshold'),
            'is_active' => __('app.workspace.active'),
        ]);

        $alertRule->fill([
            'threshold' => $this->threshold(AlertType::from($alertRule->type), $data['threshold'] ?? null),
            'is_active' => $data['is_active'],
        ])->save();

        return back();
    }

    /**
     * An empty threshold uses the type default. Stored null is cast to 0 by the detectors, so a
     * visibility drop would alert on any positive change instead of the documented default.
     */
    private function threshold(?AlertType $type, mixed $given): ?string
    {
        if ($given !== null && $given !== '') {
            return is_numeric($given) ? (string) $given : null;
        }
        $default = $type?->defaultThreshold();

        return $default === null ? null : (string) $default;
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
