<?php

namespace App\Http\Controllers;

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\Sources\FactCorrectionSource;
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
        abort_if($claim->fact_id === null, 422);
        $now = CarbonImmutable::now($brand->tenant->timezone);
        $action = DB::transaction(function () use ($claim, $brand, $source, $generator, $now) {
            $fact = $brand->facts()->lockForUpdate()->findOrFail($claim->fact_id);
            abort_unless($fact->is_active && $fact->claims()->contradicted()->whereKey($claim->id)
                ->whereHas('response', FactCorrectionSource::inboxResponses($now))->exists(), 422);
            $candidate = $source->requested($fact, $now);
            abort_if($candidate === null, 422);

            return $generator->requestCorrection($brand, $candidate, $now);
        });

        return back()->with('action_id', $action->id);
    }
}
