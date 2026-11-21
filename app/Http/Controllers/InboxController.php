<?php

namespace App\Http\Controllers;

use App\ActionEngine\ActionCandidate;
use App\ActionEngine\ActionGenerator;
use App\ActionEngine\Sources\FactCorrectionSource;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Claim;
use App\Models\Prompt;
use App\Models\UserBrandPreference;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InboxController extends Controller
{
    public function hide(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);
        UserBrandPreference::query()->upsert([
            ['brand_id' => $brand->id, 'user_id' => $request->user()?->id, 'checklist_hidden_at' => now()],
        ], ['tenant_id', 'user_id', 'brand_id'], ['checklist_hidden_at']);

        return back();
    }

    public function correct(Brand $brand, Claim $claim, FactCorrectionSource $source, ActionGenerator $generator): RedirectResponse
    {
        abort_unless($claim->brand_id === $brand->id, 404);
        Gate::authorize('create', Prompt::class);
        $now = CarbonImmutable::now();
        abort_unless($claim->verdict === Claim::CONTRADICTED && $claim->fact_id !== null
            && $claim->response?->collected_at?->between($now->subDays(30), $now), 422);
        $fingerprint = ActionCandidate::fingerprintOf(ActionType::FactCorrection, $brand->id, null, 'fact:'.$claim->fact_id);
        $action = DB::transaction(function () use ($claim, $brand, $source, $generator, $fingerprint, $now) {
            $fact = $brand->facts()->lockForUpdate()->findOrFail($claim->fact_id);
            abort_unless($fact->is_active, 422);
            $existing = Action::query()->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                return $existing;
            }
            $candidate = $source->requested($fact, $now);
            abort_if($candidate === null, 422);

            return $generator->create($brand, $candidate, null, $brand->tenant, $now);
        });

        return back()->with('action_id', $action->id);
    }
}
