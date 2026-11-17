<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Jobs\GenerateTenantReports;
use App\Models\Team;
use Illuminate\Console\Command;
use Throwable;

/**
 * Generates and sends the latest completed PDF report for each active tenant (docs/spec.md 11.2).
 * Safe to start every hour: a period is stored once, and a stored report is only mailed until it is sent.
 */
class GenerateReports extends Command
{
    protected $signature = 'ekko:reports
        {--tenant=* : Only these tenant ids}
        {--sync : Run in this process instead of on the queue}';

    protected $description = 'Generate and send the latest completed PDF report per tenant';

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
            $job = new GenerateTenantReports((int) $tenantId);
            if (! $this->option('sync')) {
                dispatch($job);

                continue;
            }

            try {
                dispatch_sync($job);
            } catch (Throwable $e) {
                report($e);
                $failed[] = $tenantId;
            }
        }

        if ($failed !== []) {
            $this->components->error(__('reports.command.failed', ['failed' => count($failed), 'tenants' => $tenants->count()]));

            return self::FAILURE;
        }

        $this->components->info(__($this->option('sync') ? 'reports.command.done' : 'reports.command.queued', ['tenants' => $tenants->count()]));

        return self::SUCCESS;
    }
}
