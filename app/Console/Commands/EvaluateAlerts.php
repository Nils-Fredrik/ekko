<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Jobs\EvaluateTenantAlerts;
use App\Models\Team;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evaluates the alert types and delivers the daily digest (docs/spec.md 11.1, T24). One job per active tenant,
 * so every tenant runs under its own context and row-level security. Safe to start every hour: a tenant is
 * evaluated once per local day, after ekko.alerts.evaluate_after.
 */
class EvaluateAlerts extends Command
{
    protected $signature = 'ekko:evaluate-alerts
        {--tenant=* : Only these tenant ids}
        {--sync : Run in this process instead of on the queue}';

    protected $description = 'Evaluate the alert types and deliver the daily alert digest per tenant';

    public function handle(): int
    {
        $tenants = Team::query()
            ->where('personal_team', false)
            ->where('status', TenantStatus::Active->value)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', array_map('intval', (array) $ids)))
            ->orderBy('id')
            ->pluck('id');

        $failed = [];
        foreach ($tenants as $tenantId) {
            $job = new EvaluateTenantAlerts((int) $tenantId);
            if (! $this->option('sync')) {
                dispatch($job);

                continue;
            }

            // One tenant that fails must not stop the others. The error is reported, and the command fails at
            // the end, so the scheduler sees it.
            try {
                dispatch_sync($job);
            } catch (Throwable $e) {
                report($e);
                $failed[] = $tenantId;
            }
        }

        if ($failed !== []) {
            $this->components->error(__('alerts.command.failed', ['failed' => count($failed), 'tenants' => $tenants->count(), 'ids' => implode(', ', $failed)]));

            return self::FAILURE;
        }

        $this->components->info(__($this->option('sync') ? 'alerts.command.done' : 'alerts.command.queued', ['tenants' => $tenants->count()]));

        return self::SUCCESS;
    }
}
