<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\WorkSignals;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * The recovery sweep for durable work (docs/spec.md 7.1, ADR 0032): publishes the wake-up again for signals that
 * are due but were never woken (a crash between commit and publish, a failed publish) or whose lease expired.
 * Reads only pending rows and starts no processing itself; recomputes nothing. Not scheduled yet: T40-F4 runs it
 * as the hourly reserve check.
 */
class RecoverWorkSignals extends Command
{
    protected $signature = 'ekko:work:recover';

    protected $description = 'Publish wake-ups again for durable work signals that were never woken or whose lease expired';

    public function handle(TenantContext $context, TenantRls $rls, WorkSignals $signals): int
    {
        $now = CarbonImmutable::now('UTC');
        $published = 0;
        $failed = 0;

        foreach (Team::query()->where('personal_team', false)->where('status', '<>', TenantStatus::Closed->value)->select('id')->lazyById() as $tenant) {
            try {
                // Read in the tenant's own transaction, publish after it, outside any transaction.
                $wakeUps = $context->run($tenant->id, fn () => $rls->run([$tenant->id], fn () => $signals->stranded($now)));
                foreach ($wakeUps as $wakeUp) {
                    $signals->publish($wakeUp) ? $published++ : $failed++;
                }
            } catch (Throwable $failure) {
                report($failure);
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->components->error(__('work.recover.failed', ['published' => $published, 'failed' => $failed]));

            return self::FAILURE;
        }

        $this->components->info(__('work.recover.done', ['published' => $published]));

        return self::SUCCESS;
    }
}
