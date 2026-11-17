<?php

namespace App\Console\Commands;

use App\Logging\LogContext;
use App\Monitoring\HealthSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorHealth extends Command
{
    protected $signature = 'ekko:monitor-health';

    protected $description = 'Emit numeric platform health signals using isolated tenant transactions';

    public function handle(HealthSnapshot $health, LogContext $context): int
    {
        return $context->run(['tenant_id' => null, 'user_id' => null, 'run_id' => null], function () use ($health): int {
            $now = CarbonImmutable::now('UTC');
            // An exception aborts the job and omits the heartbeat. Never cache a partial healthy snapshot.
            $snapshot = $health->collect($now);
            Cache::put('monitoring.engine_health', ['checked_at' => $now->toIso8601String(), 'engines' => $snapshot['engines']], $now->addMinutes(30));
            foreach ($snapshot['engines'] as $key => $engine) {
                if ($engine['unhealthy']) {
                    Log::warning('monitoring.issue', ['signal' => 'engine_error_rate', 'engine_key' => $key, 'total' => $engine['total'], 'failed' => $engine['failed']]);
                }
            }
            foreach (['collection_overdue', 'extraction_overdue'] as $signal) {
                if ($snapshot[$signal] > 0) {
                    Log::warning('monitoring.issue', ['signal' => $signal, 'count' => $snapshot[$signal]]);
                }
            }
            Log::info('monitoring.completed');

            return self::SUCCESS;
        });
    }
}
