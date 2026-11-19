<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Jobs\GenerateTenantActions;
use App\Models\Team;
use Illuminate\Console\Command;
use Throwable;

/**
 * Generates the actions of every active tenant (docs/spec.md 11.3, T43). Weekly; a brand counts one generation
 * per local ISO week, so a second start the same week only refreshes with --force.
 */
class GenerateActions extends Command
{
    protected $signature = 'ekko:generate-actions
        {--tenant=* : Only these tenant ids}
        {--force : Refresh brands already generated this week (without counting towards expiry)}
        {--sync : Run in this process instead of on the queue}';

    protected $description = 'Generate and rank the actions per brand from the latest figures';

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
            $job = new GenerateTenantActions((int) $tenantId, (bool) $this->option('force'));
            if (! $this->option('sync')) {
                dispatch($job);

                continue;
            }

            // One tenant that fails must not stop the others; the command fails at the end so the scheduler sees it.
            try {
                dispatch_sync($job);
            } catch (Throwable $e) {
                report($e);
                $failed[] = $tenantId;
            }
        }

        if ($failed !== []) {
            $this->components->error(__('action_items.command.failed', ['failed' => count($failed), 'tenants' => $tenants->count(), 'ids' => implode(', ', $failed)]));

            return self::FAILURE;
        }

        $this->components->info(__($this->option('sync') ? 'action_items.command.done' : 'action_items.command.queued', ['tenants' => $tenants->count()]));

        return self::SUCCESS;
    }
}
