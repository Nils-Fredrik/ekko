<?php

namespace App\Work;

use App\Enums\TenantStatus;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\CloudTasks\CloudTasksWorkPublisher;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The rare recovery check for durable work (docs/spec.md 7.1, ADR 0032/0033). It recomputes nothing and starts
 * nothing when there is no work:
 *
 * - sweep(): per tenant, under its own row-level security, one indexed read of pending signals; a wake-up is
 *   published again for each that is due without a live lease (never published, publish failed, lease expired after
 *   a crash). Stopped signals are left alone; the health check alerts on them.
 * - queue(): queue jobs that wait while no worker runs get the fixed dispatcher woken (web only, ADR 0030); jobs that
 *   wait for a later time get a queue wake-up then (Cloud Tasks only).
 */
class WorkRecovery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRls $rls,
        private readonly WorkSignals $signals,
        private readonly WorkerWake $workers,
    ) {}

    /** @return array{published: int, failed: int} */
    public function sweep(CarbonImmutable $now): array
    {
        $published = 0;
        $failed = 0;

        foreach (Team::query()->where('personal_team', false)->where('status', '<>', TenantStatus::Closed->value)->select('id')->lazyById() as $tenant) {
            try {
                // Read in the tenant's own transaction, publish after it, outside any transaction.
                $wakeUps = $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], fn () => $this->signals->stranded($now)));
                foreach ($wakeUps as $wakeUp) {
                    $this->signals->publish($wakeUp) ? $published++ : $failed++;
                }
            } catch (Throwable $failure) {
                report($failure);
                $failed++;
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }

    /** @return 'idle'|'scheduled'|WorkerWake::BUSY|WorkerWake::COOLDOWN|WorkerWake::WOKEN|WorkerWake::RUNNING */
    public function queue(): string
    {
        if ($this->workers->waitingNow()) {
            return $this->workers->ensure();
        }

        $next = $this->workers->nextWaiting();
        if ($next !== null && config('ekko.work.publisher') === 'cloud_tasks' && ! $this->workers->workerBusy()) {
            app(CloudTasksWorkPublisher::class)->queueWake($next);

            return 'scheduled';
        }

        return 'idle';
    }
}
