<?php

namespace App\Http\Controllers;

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\Sources\FactCorrectionSource;
use App\Inbox\InboxActionUndo;
use App\Inbox\InboxQuery;
use App\Models\Action;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Claim;
use App\Models\EntityDecision;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\UserBrandPreference;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    private const TABS = ['alerts', 'entities', 'actions', 'claims', 'integrations'];

    public function index(Request $request, InboxQuery $inbox): Response
    {
        Gate::authorize('viewAny', Alert::class);
        $team = Team::query()->findOrFail(current_tenant_id());
        $brand = $this->brand($request);
        $market = $this->market($request, $brand);
        $type = $request->query('type');
        $tab = is_string($type) && in_array($type, self::TABS, true) ? $type : 'all';

        return Inertia::render('Inbox/Index', [
            'items' => $inbox->items($team, $brand, $market, $tab === 'all' ? null : $tab, $request->query('handled') === '1'),
            'counts' => $inbox->counts($team, $brand, $market),
            'filters' => [
                'brand' => $brand?->id,
                'market' => $market?->id,
                'type' => $tab,
                'handled' => $request->query('handled') === '1',
            ],
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'can' => [
                'alerts' => Gate::allows('create', AlertRule::class),
                'entities' => Gate::allows('create', EntityDecision::class),
                'actions' => Gate::allows('create', Action::class),
                'claims' => Gate::allows('create', Prompt::class),
            ],
        ]);
    }

    /** /alerts is the old list. Rules live under the brand; the rows live in the inbox. */
    public function legacy(): RedirectResponse
    {
        Gate::authorize('viewAny', Alert::class);

        return redirect()->route('inbox.index', ['type' => 'alerts']);
    }

    public function hide(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);
        UserBrandPreference::query()->upsert([
            ['brand_id' => $brand->id, 'user_id' => $request->user()?->id, 'checklist_hidden_at' => now()],
        ], ['tenant_id', 'user_id', 'brand_id'], ['checklist_hidden_at']);

        return back();
    }

    public function correct(Request $request, Brand $brand, Claim $claim, FactCorrectionSource $source, ActionGenerator $generator, InboxActionUndo $undo): RedirectResponse|JsonResponse
    {
        abort_unless($claim->brand_id === $brand->id, 404);
        Gate::authorize('create', Prompt::class);
        abort_if($claim->fact_id === null, 422);
        $now = CarbonImmutable::now($brand->tenant->timezone);
        $receipt = $request->boolean('inbox_undo');
        [$action, $token] = DB::transaction(function () use ($claim, $brand, $source, $generator, $now, $undo, $request, $receipt) {
            $fact = $brand->facts()->lockForUpdate()->findOrFail($claim->fact_id);
            abort_unless($fact->is_active && $fact->claims()->contradicted()->whereKey($claim->id)
                ->whereHas('response', FactCorrectionSource::inboxResponses($now))->exists(), 422);
            $candidate = $source->requested($fact, $now);
            abort_if($candidate === null, 422);

            $action = $generator->findOrCreateCorrection($brand, $candidate, $now);
            $before = $receipt ? $undo->before($action) : null;
            $generator->requestCorrection($action, $candidate, $now);

            return [$action, $receipt ? $undo->receipt($action, $before, (int) $request->user()?->id) : null];
        });

        return $receipt ? response()->json(['action_id' => $action->id, 'undo_token' => $token]) : back()->with('action_id', $action->id);
    }

    public function undoAction(Request $request, Brand $brand, Action $action, InboxActionUndo $undo): JsonResponse
    {
        abort_unless($action->brand_id === $brand->id, 404);
        Gate::authorize('update', $action);
        $token = $request->validate(['undo_token' => ['required', 'uuid']])['undo_token'];
        $undo->undo($action, (int) $request->user()?->id, $token);

        return response()->json(null, 204);
    }

    private function brand(Request $request): ?Brand
    {
        $id = filter_var($request->query('brand'), FILTER_VALIDATE_INT);

        return $id === false ? null : Brand::query()->find($id);
    }

    private function market(Request $request, ?Brand $brand): ?Market
    {
        if ($brand === null) {
            return null;
        }
        $id = filter_var($request->query('market'), FILTER_VALIDATE_INT);

        return $id === false ? null : $brand->markets()->whereKey($id)->first();
    }
}
