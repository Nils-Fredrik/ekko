<?php

namespace App\Http\Controllers;

use App\Alerts\AlertPresenter;
use App\Alerts\AlertRules;
use App\Alerts\AlertType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alerts and the rules behind them (docs/spec.md 11.1 and 14.2, T28).
 */
class AlertController extends Controller
{
    public function index(AlertPresenter $presenter): Response
    {
        Gate::authorize('viewAny', Alert::class);

        $alerts = Alert::query()->with('alertRule.brand')->latest('triggered_at')->limit(50)->get();
        $rules = AlertRule::query()->with('brand')->orderBy('id')->get();

        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts->map(function (Alert $alert) use ($presenter): array {
                $presented = $presenter->present($alert);

                return [
                    'id' => $alert->id,
                    'type' => $alert->alertRule->type,
                    'brand' => $alert->alertRule->brand?->name,
                    'title' => $presented['title'],
                    'detail' => $presented['detail'],
                    'triggered_at' => $alert->triggered_at->toIso8601String(),
                    'acknowledged' => $alert->acknowledged_at !== null,
                ];
            })->values(),
            'rules' => $rules->map(fn (AlertRule $rule): array => [
                'id' => $rule->id,
                'brand_id' => $rule->brand_id,
                'brand' => $rule->brand?->name,
                'type' => $rule->type,
                'threshold' => $rule->threshold,
                'channels' => array_values(array_unique(['inbox', ...array_intersect($rule->channels, ['email', 'slack'])])),
                'is_active' => $rule->is_active,
            ])->values(),
            'tenantWideTypes' => collect(AlertType::cases())->filter(fn (AlertType $type) => $type->isTenantWide())->pluck('value')->values(),
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
            'brand_id' => [Rule::requiredIf(! $tenantWide), Rule::prohibitedIf($type === AlertType::IntegrationError), 'nullable', 'integer'],
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
        app(AlertRules::class)->save(AlertType::from($data['type']), $brand?->id, $data, createOnly: true);

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

        app(AlertRules::class)->save(AlertType::from($alertRule->type), $alertRule->brand_id, $data, existing: $alertRule);

        return back();
    }

    public function upsert(Request $request, Brand $brand, AlertType $type, AlertRules $rules): RedirectResponse
    {
        Gate::authorize('create', AlertRule::class);
        $data = $request->validate([
            'is_active' => ['sometimes', 'required', 'boolean'],
            'threshold' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'channels' => ['sometimes', 'array', 'max:3'],
            'channels.*' => ['required', 'distinct', Rule::in(['inbox', 'email', 'slack'])],
        ]);
        $rules->save($type, $brand->id, $data);

        return back();
    }

    public function acknowledgeBulk(Request $request): RedirectResponse
    {
        return $this->receipts($request, false);
    }

    public function undoBulk(Request $request): RedirectResponse
    {
        return $this->receipts($request, true);
    }

    private function receipts(Request $request, bool $undo): RedirectResponse
    {
        Gate::authorize('create', AlertRule::class);
        $ids = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ])['ids'];
        $userId = $request->user()?->id;
        $changed = DB::transaction(function () use ($ids, $userId, $undo) {
            $alerts = Alert::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($alerts->count() === count($ids), 404);
            $changed = [];
            foreach ($alerts as $alert) {
                $eligible = $undo ? $alert->acknowledged_by === $userId && $alert->acknowledged_at !== null : $alert->acknowledged_at === null;
                if ($eligible) {
                    $alert->forceFill(['acknowledged_by' => $undo ? null : $userId, 'acknowledged_at' => $undo ? null : now()])->save();
                    $changed[] = $alert->id;
                }
            }

            return $changed;
        });

        return back()->with('acknowledged_ids', $changed);
    }

    public function acknowledge(Alert $alert): RedirectResponse
    {
        Gate::authorize('update', $alert);

        // The first receipt stands: acknowledging again does not change who or when.
        DB::transaction(function () use ($alert) {
            $locked = Alert::query()->lockForUpdate()->findOrFail($alert->id);
            if ($locked->acknowledged_at === null) {
                $locked->update(['acknowledged_by' => request()->user()?->id, 'acknowledged_at' => now()]);
            }
        });

        return back();
    }
}
