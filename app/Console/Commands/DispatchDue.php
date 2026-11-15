<?php

namespace App\Console\Commands;

use App\Scheduling\Dispatcher;
use App\Scheduling\WorkerLauncher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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

            if ($dispatcher->failures > 0) {
                $this->components->error(__('collection.failed'));

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $failure) {
            Log::error('Collection dispatch failed.', ['error_type' => $failure::class]);
            $this->components->error(__('collection.failed'));

            return self::FAILURE;
        }
    }
}
