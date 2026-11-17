<?php

namespace App\Onboarding;

use App\Jobs\CollectEngineRun;
use App\Models\Market;
use App\Models\RunBatch;
use App\Models\Team;
use App\Scheduling\WorkerLauncher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Starts the first run of a confirmed market immediately, for its core prompts (docs/spec.md 13).
 * Later runs follow the ordinary schedule.
 */
final class FirstRun
{
    public function start(Team $tenant, Market $market): int
    {
        $now = CarbonImmutable::now('UTC');
        $day = $now->setTimezone($tenant->timezone)->startOfDay();
        $batch = RunBatch::query()->firstOrCreate(
            ['market_id' => $market->id, 'dispatch_date' => $day->toDateString()],
            ['scheduled_for' => $day->utc(), 'status' => 'queued'],
        );
        $prompts = $market->prompts()->where('is_active', true)->where('priority', 'core')->orderBy('id')->get();
        $engines = $market->marketEngines()->where('frequency', '!=', 'off')->orderBy('id')->get();
        $count = 0;

        foreach ($prompts as $prompt) {
            foreach ($engines as $engine) {
                for ($repetition = 1; $repetition <= $engine->repetitions; $repetition++) {
                    $run = $batch->runs()->create([
                        'prompt_id' => $prompt->id,
                        'engine_key' => $engine->engine_key,
                        'repetition' => $repetition,
                        'status' => 'queued',
                        'attempts' => 0,
                    ]);
                    Queue::connection('database')->push(new CollectEngineRun($tenant->id, $run->id));
                    $run->forceFill(['enqueued_at' => $now])->save();
                    $count++;
                }
            }
        }

        $batch->update(['planned_runs' => $batch->runs()->count()]);

        try {
            app(WorkerLauncher::class)->startIfPending();
        } catch (Throwable $e) {
            report($e);
        }

        return $count;
    }
}
