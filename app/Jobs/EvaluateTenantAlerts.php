<?php

namespace App\Jobs;

use App\Alerts\AlertDelivery;
use App\Alerts\AlertEvaluator;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\AlertEvaluation;
use App\Models\Run;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The daily alert run of one tenant (docs/spec.md 11.1, ADR 0017). Started every hour. Before the evaluation
 * time (ekko.alerts.evaluate_after, tenant-local) it does nothing, and until ekko.alerts.evaluate_wait_until it
 * waits while answers from yesterday are still being collected or extracted (only extracted answers count, ADR
 * 0012). The first run after that claims the local day (alert_evaluations), evaluates the alert types and
 * delivers the digest. Later runs the same day only retry channels that failed.
 *
 * The job does not run in one transaction: the evaluation is one, and the delivery sends messages outside a
 * transaction and saves each receipt in its own (see AlertDelivery), so a failure after a message was sent
 * cannot roll the receipt back. Like CollectEngineRun it overrides middleware() and sets row-level security
 * itself.
 */
class EvaluateTenantAlerts extends TenantJob
{
    private const UNFINISHED = ['queued', 'running', 'submitted', 'collected'];

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(AlertEvaluator $evaluator, AlertDelivery $delivery, TenantRls $rls): void
    {
        $now = CarbonImmutable::now();

        $date = $rls->run([$this->tenantId], function () use ($evaluator, $delivery, $now): ?string {
            $tenant = Team::query()->findOrFail($this->tenantId);
            $timezone = $this->timezone((string) $tenant->timezone);
            $local = $now->setTimezone($timezone);

            if ($local->lt($this->at($local, (string) config('ekko.alerts.evaluate_after')))) {
                return null;
            }

            $date = $local->toDateString();
            if (! AlertEvaluation::query()->where('local_date', $date)->exists()) {
                if ($this->awaitsExtraction($local)) {
                    return null;
                }

                if ($this->claim($date, $now)) {
                    $evaluator->evaluate($now, $timezone);
                    $delivery->assignDigest($now, $date);
                }
            }

            return $date;
        });

        if ($date !== null) {
            $delivery->deliver($now, $date);
        }
    }

    /**
     * Whether this run is the one that takes the local day. The unique key decides it when runs overlap.
     */
    private function claim(string $date, CarbonImmutable $now): bool
    {
        try {
            // A savepoint, so a lost race does not abort the transaction.
            DB::transaction(fn () => AlertEvaluation::query()->create(['local_date' => $date, 'evaluated_at' => $now->utc()]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * True before the wait is over while runs from yesterday on are not finished, so yesterday's last
     * answers are in the figures before they are compared.
     */
    private function awaitsExtraction(CarbonImmutable $local): bool
    {
        if ($local->lt($this->at($local, (string) config('ekko.alerts.evaluate_wait_until')))) {
            $since = $local->startOfDay()->subDay()->utc();

            return Run::query()
                ->whereIn('status', self::UNFINISHED)
                ->whereHas('runBatch', fn ($batch) => $batch->where('scheduled_for', '>=', $since))
                ->exists();
        }

        return false;
    }

    private function at(CarbonImmutable $local, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $local->setTime($hour, $minute);
    }

    private function timezone(string $name): string
    {
        return in_array($name, timezone_identifiers_list(), true) ? $name : 'UTC';
    }
}
