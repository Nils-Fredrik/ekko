<?php

namespace App\Work;

use App\Scheduling\DispatcherLauncher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes sure a worker will pick up waiting queue jobs, along ADR 0030's bounded chain (T40-F4, ADR 0033): web wakes
 * only the fixed poll dispatcher (ekko-wake, no overrides), and only that dispatcher may start ekko-worker.
 *
 * An active worker is preferred: a job reserved within the queue's reservation time means a worker process is in
 * the middle of a job and pops the next one afterwards, so nothing is started. Otherwise the dispatcher is woken, at
 * most once per launch_cooldown_seconds; it starts the worker only when no worker execution is active. Neither step
 * can prove that the work will be picked up (a worker may be exiting), so callers keep their wake-up open until the
 * work has moved on, and come back.
 */
class WorkerWake
{
    public const BUSY = 'busy';

    public const COOLDOWN = 'cooldown';

    public const WOKEN = 'woken';

    public const RUNNING = 'running';

    private const COOLDOWN_KEY = 'ekko:work:wake-launch';

    public function __construct(private readonly DispatcherLauncher $launcher) {}

    /** Whether a worker process is evidently at work: a job it reserved has not outlived the reservation time. */
    public function workerBusy(): bool
    {
        return $this->jobs()->whereNotNull('reserved_at')->where('reserved_at', '>', $this->reservationCutoff())->exists();
    }

    /** Whether a queue job can be taken now: available, and not reserved by a live worker. */
    public function waitingNow(): bool
    {
        return $this->unreserved()->where('available_at', '<=', $this->now())->exists();
    }

    /** The earliest time a waiting job becomes available, when all waiting jobs are delayed. */
    public function nextWaiting(): ?CarbonImmutable
    {
        $at = $this->unreserved()->where('available_at', '>', $this->now())->min('available_at');

        return $at === null ? null : CarbonImmutable::createFromTimestampUTC((int) $at);
    }

    /**
     * Wakes the dispatcher unless a worker is evidently busy or a recent wake is still under way.
     *
     * @return self::BUSY|self::COOLDOWN|self::WOKEN|self::RUNNING
     */
    public function ensure(): string
    {
        if ($this->workerBusy()) {
            return self::BUSY;
        }
        $cooldown = max(1, (int) config('ekko.work.wake.launch_cooldown_seconds'));
        if (! Cache::add(self::COOLDOWN_KEY, $this->now(), $cooldown)) {
            return self::COOLDOWN;
        }

        try {
            // False: a dispatcher is already running (it checks the worker itself), or starting is switched off.
            return $this->launcher->start() ? self::WOKEN : self::RUNNING;
        } catch (Throwable $failure) {
            // The next delivery may try again at once.
            Cache::forget(self::COOLDOWN_KEY);

            throw $failure;
        }
    }

    private function jobs(): Builder
    {
        return DB::table((string) config('queue.connections.database.table', 'jobs'));
    }

    private function unreserved(): Builder
    {
        return $this->jobs()->where(fn (Builder $query) => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', $this->reservationCutoff()));
    }

    private function reservationCutoff(): int
    {
        return $this->now() - max(1, (int) config('queue.connections.database.retry_after', 90));
    }

    /** The queue's own clock (Laravel's database queue writes Carbon timestamps). */
    private function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
