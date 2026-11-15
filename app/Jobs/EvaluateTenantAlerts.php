<?php

namespace App\Jobs;

use App\Alerts\AlertDelivery;
use App\Alerts\AlertEvaluator;
use App\Models\AlertEvaluation;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The daily alert run of one tenant (docs/spec.md 11.1, ADR 0017). Started every hour. Before the evaluation
 * time (ekko.alerts.evaluate_after, tenant-local) it does nothing. The first run after it claims the local day
 * (alert_evaluations), evaluates the alert types and delivers the digest. Later runs the same day only retry
 * channels that failed.
 */
class EvaluateTenantAlerts extends TenantJob
{
    public function handle(AlertEvaluator $evaluator, AlertDelivery $delivery): void
    {
        $tenant = Team::query()->findOrFail($this->tenantId);
        $timezone = $this->timezone((string) $tenant->timezone);
        $now = CarbonImmutable::now();
        $local = $now->setTimezone($timezone);

        [$hour, $minute] = array_map('intval', explode(':', (string) config('ekko.alerts.evaluate_after')));
        if ($local->lt($local->setTime($hour, $minute))) {
            return;
        }

        $date = $local->toDateString();
        $claimed = $this->claim($date, $now);
        if ($claimed) {
            $evaluator->evaluate($now, $timezone);
        }

        $delivery->deliver($now, $date, assignNew: $claimed);
    }

    /**
     * Whether this run is the first of the local day. The unique key decides it when runs overlap.
     */
    private function claim(string $date, CarbonImmutable $now): bool
    {
        if (AlertEvaluation::query()->where('local_date', $date)->exists()) {
            return false;
        }

        try {
            // A savepoint, so a lost race does not abort the job's transaction.
            DB::transaction(fn () => AlertEvaluation::query()->create(['local_date' => $date, 'evaluated_at' => $now->utc()]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function timezone(string $name): string
    {
        return in_array($name, timezone_identifiers_list(), true) ? $name : 'UTC';
    }
}
