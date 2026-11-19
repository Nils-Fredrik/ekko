<?php

namespace App\Work\Listeners;

use App\Work\CloudTasks\CloudTasksWorkPublisher;
use App\Work\WorkerWake;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A stopping worker process registers its own next start (T40-F4, ADR 0033), instead of a fixed poll finding it:
 * when queue jobs wait for a later time (a released or delayed job), a queue wake-up is published for the earliest
 * of them; when a job became available just as the worker stopped, for now. Every process of the execution asks for
 * the same task id, so they coalesce. Only with the Cloud Tasks publisher; a failure is logged and left to the
 * recovery check.
 */
final class PublishQueueWakeOnWorkerStop
{
    public function __construct(private readonly WorkerWake $workers) {}

    public function handle(WorkerStopping $event): void
    {
        if (config('ekko.work.publisher') !== 'cloud_tasks') {
            return;
        }

        try {
            $at = $this->workers->waitingNow() ? CarbonImmutable::now('UTC') : $this->workers->nextWaiting();
            if ($at !== null) {
                app(CloudTasksWorkPublisher::class)->queueWake($at);
            }
        } catch (Throwable $failure) {
            Log::warning('work.queue_wake_not_published', ['error_type' => $failure::class]);
        }
    }
}
