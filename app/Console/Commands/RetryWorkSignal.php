<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\WorkSignals;
use Illuminate\Console\Command;

/**
 * Re-arms one stopped work signal (docs/spec.md 7.1, ADR 0032) after the cause of its failures is fixed. The tenant
 * is an argument and the signal is looked up only in that tenant's context and row-level security, so an id from
 * another tenant is simply not found.
 */
class RetryWorkSignal extends Command
{
    protected $signature = 'ekko:work:retry {tenant : Tenant id} {signal : Work signal id}';

    protected $description = 'Re-arm a durable work signal that was stopped after failing for too long';

    public function handle(TenantContext $context, TenantRls $rls, WorkSignals $signals): int
    {
        $tenantId = filter_var($this->argument('tenant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $signalId = filter_var($this->argument('signal'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenantId === false || $signalId === false || ! Team::query()->whereKey($tenantId)->where('personal_team', false)->exists()) {
            $this->components->error(__('work.retry.not_found'));

            return self::FAILURE;
        }

        $rearmed = $context->run($tenantId, fn () => $rls->run([$tenantId], fn () => $signals->rearm($signalId)));
        if (! $rearmed) {
            $this->components->error(__('work.retry.not_found'));

            return self::FAILURE;
        }

        $this->components->info(__('work.retry.done'));

        return self::SUCCESS;
    }
}
