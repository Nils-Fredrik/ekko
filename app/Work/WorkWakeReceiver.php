<?php

namespace App\Work;

use App\Enums\TenantStatus;
use App\Jobs\ProcessWorkSignal;
use App\Models\Team;
use App\Models\WorkSignal;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\CloudTasks\CloudTasksWorkPublisher;
use Carbon\CarbonImmutable;

/**
 * Receives Cloud Tasks wake-ups on web (T40-F4, ADR 0033). Delivery is at least once and may be late, so every step
 * is idempotent and reads the signal row, the source of truth, under the tenant's own context and row-level
 * security. A signal id of another tenant is simply not found.
 *
 * A delivery is settled (open() false, HTTP 2xx) when nothing is left for it to guard: the signal is unknown, closed,
 * stopped, processed, or waits for a later time that its own wake-up covers. Otherwise it queues ProcessWorkSignal
 * (unique per signal version, so repeated deliveries queue one job), makes sure a worker will run (WorkerWake), and
 * stays open (HTTP 425), so Cloud Tasks comes back until a worker has taken the work and the lease or acknowledgement
 * shows it. That covers a worker that exits right after a new start was refused, and a worker crash: an expired lease
 * counts as due again. Web never processes the work itself and never starts the worker directly.
 */
class WorkWakeReceiver
{
    public const UNKNOWN = 'unknown';

    public const IDLE = 'idle';

    public const STOPPED = 'stopped';

    public const NOT_DUE = 'not_due';

    public const LEASED = 'leased';

    public const QUEUED = 'queued';

    public const SCHEDULED = 'scheduled';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRls $rls,
        private readonly WorkerWake $workers,
    ) {}

    /** Whether the outcome keeps the delivery open, so Cloud Tasks delivers it again. */
    public static function open(string $outcome): bool
    {
        return in_array($outcome, [self::LEASED, self::QUEUED], true);
    }

    public function signal(int $tenantId, int $signalId): string
    {
        // Personal teams have no tenant data, and a closed tenant is not processed any more (T39-F6).
        $status = Team::query()->whereKey($tenantId)->where('personal_team', false)->value('status');
        if ($status === null || $status === TenantStatus::Closed) {
            return self::UNKNOWN;
        }

        $signal = $this->context->run($tenantId, fn () => $this->rls->run([$tenantId], fn (): ?WorkSignal => WorkSignal::query()->find($signalId)));
        if ($signal === null) {
            return self::UNKNOWN;
        }
        if (! $signal->isPending()) {
            return self::IDLE;
        }
        if ($signal->stopped_at !== null) {
            return self::STOPPED;
        }

        $now = CarbonImmutable::now('UTC');
        if ($signal->lease_token !== null && $signal->lease_expires_at !== null && $signal->lease_expires_at->isAfter($now)) {
            // A processor is on it; its acknowledgement wakes what is left. Come back in case it dies.
            return self::LEASED;
        }
        if ($signal->lease_token === null && $signal->available_at->isAfter($now)) {
            // A backoff or a deferral: whoever set the time published the wake-up for it.
            return self::NOT_DUE;
        }

        // Unique per signal version until a worker starts it: deliveries of one pending version queue one job.
        dispatch(new ProcessWorkSignal($tenantId, $signalId, 'wake-'.$tenantId.'-'.$signalId.'-'.$signal->version));
        $this->workers->ensure();

        return self::QUEUED;
    }

    /**
     * A queue wake-up: queue jobs that wait for a later time, after the worker that would have taken them stopped.
     * Open while jobs can be taken now and none has been; a later wake-up is published when only delayed jobs wait.
     */
    public function queue(?CloudTasksWorkPublisher $publisher): string
    {
        if ($this->workers->waitingNow()) {
            $this->workers->ensure();

            return self::QUEUED;
        }

        $next = $this->workers->nextWaiting();
        if ($next !== null && $publisher !== null && ! $this->workers->workerBusy()) {
            $publisher->queueWake($next);

            return self::SCHEDULED;
        }

        return self::IDLE;
    }
}
