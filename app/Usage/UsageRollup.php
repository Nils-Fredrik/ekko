<?php

namespace App\Usage;

use App\Models\AlertRule;
use App\Models\Team;
use App\Models\UsageMonthly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class UsageRollup
{
    public function __construct(private MonthlyUsage $monthlyUsage) {}

    public function refresh(CarbonImmutable $now): void
    {
        DB::transaction(function () use ($now): void {
            $tenant = Team::query()->lockForUpdate()->findOrFail(current_tenant_id());
            $month = $now->utc()->startOfMonth();
            $this->save($tenant, $month, $now);

            $previousMonth = $month->subMonth();
            $previous = $this->find($previousMonth);
            if ($previous !== null) {
                $this->save($tenant, $previousMonth, $now, $previous);
            }
        });
    }

    private function find(CarbonImmutable $month): ?UsageMonthly
    {
        return UsageMonthly::query()->where('month', $month->toDateString())->first();
    }

    private function save(Team $tenant, CarbonImmutable $month, CarbonImmutable $now, ?UsageMonthly $previous = null): void
    {
        $snapshot = $this->monthlyUsage->snapshot($tenant, $month, $previous);
        $usage = $previous ?? $this->find($month) ?? new UsageMonthly;
        $usage->fill($snapshot->getAttributes());

        $threshold = $this->monthlyUsage->threshold($usage);
        if ($threshold > $usage->quota_alerted_threshold) {
            $this->alert($usage, $threshold, $now);
            $usage->quota_alerted_threshold = $threshold;
        }

        $usage->save();
    }

    private function alert(UsageMonthly $usage, int $threshold, CarbonImmutable $now): void
    {
        $rule = AlertRule::query()->firstOrCreate([
            'type' => 'quota',
            'brand_id' => null,
        ], [
            'channels' => config('ekko.alerts.default_channels'),
        ]);

        foreach ([80, 100] as $level) {
            if ($level > $usage->quota_alerted_threshold && $level <= $threshold) {
                $rule->alerts()->create([
                    'triggered_at' => $now->utc(),
                    'payload' => [
                        'month' => $usage->month->toDateString(),
                        'threshold_percent' => $level,
                        'calls' => $usage->calls,
                        'quota_calls' => $usage->quota_calls,
                        'cost_usd' => $usage->cost_usd,
                        'budget_usd' => $usage->budget_usd,
                        'byok_cost_usd' => $usage->byok_cost_usd,
                        'byok_budget_usd' => $usage->byok_budget_usd,
                        'recipient_roles' => ['tenant_admin', 'platform_admin'],
                        // At 100 % the runs stop, so the alert goes out at once, not in the next digest (ADR 0019).
                        'urgent' => $level === 100,
                    ],
                ]);
            }
        }
    }
}
