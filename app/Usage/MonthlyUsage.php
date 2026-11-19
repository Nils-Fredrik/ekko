<?php

namespace App\Usage;

use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\UsageMonthly;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class MonthlyUsage
{
    public function forAdmission(Team $tenant, CarbonImmutable $at): UsageMonthly
    {
        return $this->snapshot($tenant, $at, includeReservations: true);
    }

    public function snapshot(
        Team $tenant,
        CarbonImmutable $at,
        ?UsageMonthly $previous = null,
        bool $includeReservations = false,
    ): UsageMonthly {
        if ($tenant->id !== current_tenant_id()) {
            throw new LogicException('Cannot calculate usage outside the current tenant.');
        }

        $month = $at->utc()->startOfMonth();
        // Usage the platform pays for (actions wording, ADR 0034) does not count against the tenant's limits.
        $events = PlatformPaidUsage::exclude(UsageEvent::query())
            ->where('occurred_at', '>=', $month)
            ->where('occurred_at', '<', $month->addMonth())
            ->select('calls', 'cost_usd', 'billed_to')
            ->toBase();

        if ($includeReservations) {
            $events->unionAll(RunReservations::active($at)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('usage_events')->whereColumn('usage_events.collection_attempt_token', 'runs.quota_reservation_token'))
                ->where('quota_reserved_at', '>=', $month)
                ->where('quota_reserved_at', '<', $month->addMonth())
                ->selectRaw('1 AS calls, quota_reserved_cost_usd AS cost_usd, quota_reserved_billed_to AS billed_to')
                ->toBase());
        }

        $totals = DB::query()->fromSub($events, 'usage')
            ->selectRaw("coalesce(sum(calls), 0) AS calls, coalesce(sum(cost_usd) FILTER (WHERE billed_to = 'platform'), 0) AS platform_cost, coalesce(sum(cost_usd) FILTER (WHERE billed_to = 'byok'), 0) AS byok_cost")
            ->firstOrFail();

        $plan = $previous === null ? $tenant->plan()->first() : null;
        $usage = new UsageMonthly([
            'month' => $month->toDateString(),
            'calls' => (int) $totals->calls,
            'cost_usd' => (string) $totals->platform_cost,
            'byok_cost_usd' => (string) $totals->byok_cost,
            'quota_calls' => $previous === null
                ? ($tenant->monthly_call_quota_override ?? $plan?->monthly_call_quota)
                : $previous->quota_calls,
            'budget_usd' => $previous === null ? $plan?->monthly_budget_usd : $previous->budget_usd,
            'byok_budget_usd' => $previous === null
                ? ($tenant->byok_enabled ? $tenant->monthly_byok_budget_usd : null)
                : $previous->byok_budget_usd,
        ]);
        $usage->status = match ($this->threshold($usage)) {
            100 => 'capped',
            80 => 'warning',
            default => 'ok',
        };

        return $usage;
    }

    public function threshold(UsageMonthly $usage): int
    {
        $limits = [
            [$usage->calls, $usage->quota_calls],
            [$usage->cost_usd, $usage->budget_usd],
            [$usage->byok_cost_usd, $usage->byok_budget_usd],
        ];

        foreach ([100, 80] as $threshold) {
            foreach ($limits as [$used, $limit]) {
                if ($limit !== null && ! BigDecimal::of($used)->isZero() && BigDecimal::of($used)->multipliedBy(100)->isGreaterThanOrEqualTo(
                    BigDecimal::of($limit)->multipliedBy($threshold)
                )) {
                    return $threshold;
                }
            }
        }

        return 0;
    }
}
