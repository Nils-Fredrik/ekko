<?php

namespace App\Console\Commands;

use App\Work\WorkRecovery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The recovery sweep for durable work (docs/spec.md 7.1, ADR 0032): publishes the wake-up again for signals that
 * are due but were never woken (a crash between commit and publish, a failed publish) or whose lease expired.
 * Reads only pending rows and starts no processing itself; recomputes nothing. The hourly recovery check in
 * production is the Scheduler tick /internal/tick/work-recover on web (T40-F4), which also wakes the dispatcher for
 * waiting queue jobs; this command is the same sweep for operators.
 */
class RecoverWorkSignals extends Command
{
    protected $signature = 'ekko:work:recover';

    protected $description = 'Publish wake-ups again for durable work signals that were never woken or whose lease expired';

    public function handle(WorkRecovery $recovery): int
    {
        ['published' => $published, 'failed' => $failed] = $recovery->sweep(CarbonImmutable::now('UTC'));

        if ($failed > 0) {
            $this->components->error(__('work.recover.failed', ['published' => $published, 'failed' => $failed]));

            return self::FAILURE;
        }

        $this->components->info(__('work.recover.done', ['published' => $published]));

        return self::SUCCESS;
    }
}
