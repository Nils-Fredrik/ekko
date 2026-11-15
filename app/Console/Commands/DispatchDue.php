<?php

namespace App\Console\Commands;

use App\Scheduling\Dispatcher;
use App\Scheduling\WorkerLauncher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class DispatchDue extends Command
{
    protected $signature = 'ekko:dispatch-due {--poll : Only enqueue due provider polls and recover pending queue work}';

    protected $description = 'Plan tenant-local runs and enqueue them fairly, then start the worker if needed';

    public function handle(Dispatcher $dispatcher, WorkerLauncher $launcher): int
    {
        try {
            $count = $dispatcher->dispatch(CarbonImmutable::now('UTC'), ! $this->option('poll'));
            $launcher->startIfPending();
            $this->components->info(__('collection.queued', ['count' => $count]));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error(__('collection.failed'));

            return self::FAILURE;
        }
    }
}
