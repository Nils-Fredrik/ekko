<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardLabels;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Usage\MonthlyUsage;
use App\Usage\PlatformPaidUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's usage for the current month, against quota and budget (docs/spec.md 14.2 and 12.2, T28).
 */
class UsageController extends Controller
{
    public function __invoke(MonthlyUsage $usage): Response
    {
        Gate::authorize('viewAny', UsageEvent::class);

        $tenant = Team::query()->findOrFail(current_tenant_id());
        $now = CarbonImmutable::now('UTC');
        $month = $now->startOfMonth();
        $snapshot = $usage->snapshot($tenant, $now);
        // The same usage as the totals above: what the platform pays for is not the tenant's (ADR 0034).
        $rows = PlatformPaidUsage::exclude(UsageEvent::query())
            ->where('occurred_at', '>=', $month)
            ->where('occurred_at', '<', $month->addMonth())
            ->groupBy('engine_key')
            ->selectRaw('engine_key, coalesce(sum(calls), 0) as calls, coalesce(sum(cost_usd), 0) as cost_usd')
            ->orderBy('engine_key')
            ->get();
        $names = DashboardLabels::engines($rows->pluck('engine_key')->map(fn (mixed $key): string => (string) $key)->all());

        return Inertia::render('Usage/Index', [
            'month' => $month->toDateString(),
            'usage' => [
                'calls' => $snapshot->calls,
                'platform_cost' => $snapshot->cost_usd,
                'byok_cost' => $snapshot->byok_cost_usd,
                'quota_calls' => $snapshot->quota_calls,
                'budget_usd' => $snapshot->budget_usd,
                'byok_budget_usd' => $snapshot->byok_budget_usd,
                'status' => $snapshot->status,
            ],
            'engines' => $rows->map(fn (UsageEvent $row): array => [
                'name' => $names[(string) $row->engine_key] ?? (string) $row->engine_key,
                'calls' => (int) $row->calls,
                'cost' => (string) $row->cost_usd,
            ])->values(),
        ]);
    }
}
