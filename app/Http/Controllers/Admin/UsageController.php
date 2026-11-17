<?php

namespace App\Http\Controllers\Admin;

use App\AnthropicBudget\Account;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Team;
use App\Models\UsageMonthly;
use App\Tenancy\Rls\TenantRls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class UsageController extends Controller
{
    public function __invoke(Request $request, Account $account, TenantRls $rls): Response
    {
        Gate::authorize('viewAny', Plan::class);
        $month = now('UTC')->startOfMonth();
        $tenants = Team::query()->where('personal_team', false)->orderBy('name')->get(['id', 'name']);
        $usage = [];
        $engines = [];
        $ids = $tenants->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($ids !== []) {
            foreach ($rls->run($ids, fn () => UsageMonthly::query()->withoutGlobalScopes()->whereDate('month', $month->toDateString())->get()) as $row) {
                $usage[(int) $row->tenant_id] = $row;
            }
            foreach ($rls->run($ids, fn () => DB::table('usage_events')
                ->where('occurred_at', '>=', $month)
                ->where('occurred_at', '<', $month->copy()->addMonth())
                ->whereNotNull('engine_key')
                ->selectRaw('engine_key, sum(calls) as calls, sum(cost_usd) as cost_usd')
                ->groupBy('engine_key')
                ->orderBy('engine_key')
                ->get()) as $event) {
                $engines[] = [
                    'engine_key' => (string) $event->engine_key,
                    'calls' => (int) $event->calls,
                    'cost_usd' => (string) $event->cost_usd,
                ];
            }
        }

        return Inertia::render('Admin/Usage', [
            'anthropic' => $account->summary(),
            'month' => $month->format('Y-m'),
            'engines' => $engines,
            'tenants' => $tenants->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'calls' => (int) ($usage[$team->id]->calls ?? 0),
                'cost_usd' => (string) ($usage[$team->id]->cost_usd ?? '0.00000000'),
                'byok_cost_usd' => (string) ($usage[$team->id]->byok_cost_usd ?? '0.00000000'),
            ])->all(),
        ]);
    }
}
