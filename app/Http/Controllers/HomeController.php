<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardPeriod;
use App\Metrics\MetricsService;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\RunBatch;
use App\Models\UsageMonthly;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant overview (docs/spec.md 14.2): one line per brand, the latest alerts, the next
 * scheduled runs and usage against the quota. Every figure comes from MetricsService.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, MetricsService $metrics): Response
    {
        $period = DashboardPeriod::forUser($request->user(), 28);

        // A signed-in user without a current tenant (a personal team, or none) still reaches the
        // overview. It is empty until they are in a tenant. A role inside a tenant that may not
        // see data is refused.
        if (! Gate::allows('viewAny', Brand::class)) {
            abort_unless(app(TenantContext::class)->id() === null, 403);

            return Inertia::render('Home', [
                'period' => ['days' => $period->days, 'from' => $period->from, 'to' => $period->to],
                'brands' => [],
                'alerts' => [],
                'runs' => [],
                'usage' => null,
            ]);
        }

        $brands = Brand::query()->with('markets')->orderBy('name')->get()->map(function (Brand $brand) use ($metrics, $period) {
            $current = $metrics->visibility($period->filter($brand->id));
            $previous = $metrics->visibility($period->filter($brand->id, previous: true));

            return [
                'id' => $brand->id,
                'name' => $brand->name,
                'status' => $brand->status,
                'markets' => $brand->markets->map(fn ($market) => $market->location_label ?: strtoupper($market->country))->all(),
                'visibility' => $current->toArray(),
                'previous_visibility' => $previous->toArray(),
            ];
        })->all();

        return Inertia::render('Home', [
            'period' => ['days' => $period->days, 'from' => $period->from, 'to' => $period->to],
            'brands' => $brands,
            'alerts' => $this->alerts(),
            'runs' => $this->runs(),
            'usage' => $this->usage($period->timezone),
        ]);
    }

    /**
     * @return list<array{id: int, brand: string, type: string, triggered_at: string}>
     */
    private function alerts(): array
    {
        return Alert::query()
            ->with('alertRule.brand')
            ->latest('triggered_at')
            ->limit(5)
            ->get()
            ->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'brand' => $alert->alertRule->brand->name,
                'type' => $alert->alertRule->type,
                'triggered_at' => $alert->triggered_at->toIso8601String(),
            ])->all();
    }

    /**
     * @return list<array{id: int, brand: string, market: string, scheduled_for: string, status: string}>
     */
    private function runs(): array
    {
        return RunBatch::query()
            ->with('market.brand')
            ->where('scheduled_for', '>=', CarbonImmutable::now())
            ->where('status', '!=', 'completed')
            ->orderBy('scheduled_for')
            ->limit(5)
            ->get()
            ->map(fn (RunBatch $batch) => [
                'id' => $batch->id,
                'brand' => $batch->market->brand->name,
                'market' => $batch->market->location_label ?: strtoupper($batch->market->country),
                'scheduled_for' => $batch->scheduled_for->toIso8601String(),
                'status' => $batch->status,
            ])->all();
    }

    /**
     * @return array{calls: int, quota_calls: int|null, cost_usd: float|string, budget_usd: float|string|null, status: string}|null
     */
    private function usage(string $timezone): ?array
    {
        if (! Gate::allows('viewAny', UsageMonthly::class)) {
            return null;
        }

        $month = CarbonImmutable::now($timezone)->startOfMonth()->toDateString();
        $usage = UsageMonthly::query()->whereDate('month', $month)->first();

        if ($usage === null) {
            return [
                'calls' => 0,
                'quota_calls' => null,
                'cost_usd' => '0',
                'budget_usd' => null,
                'status' => 'ok',
            ];
        }

        return [
            'calls' => $usage->calls,
            'quota_calls' => $usage->quota_calls,
            'cost_usd' => $usage->cost_usd,
            'budget_usd' => $usage->budget_usd,
            'status' => $usage->status,
        ];
    }
}
