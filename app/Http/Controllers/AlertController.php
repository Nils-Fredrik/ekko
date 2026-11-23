<?php

namespace App\Http\Controllers;

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

/**
 * Alerts and the rules behind them (docs/spec.md 11.1 and 14.2, T28).
 */
class AlertController extends Controller
{
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
