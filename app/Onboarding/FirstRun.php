<?php

namespace App\Onboarding;

use App\AnthropicBudget\Account;
use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EnginePricing;
use App\Jobs\CollectEngineRun;
use App\Models\Market;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Scheduling\DispatcherLauncher;
use App\Scheduling\PromptSchedule;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

/** Immediate core collection plus today's scheduled prompts; subsequent batches use the ordinary dispatcher. */
final class FirstRun
{
    public function __construct(private EnginePricing $prices, private TenantApiKeys $keys, private MonthlyUsage $usage, private QuotaGuard $quota) {}

    public function start(Team $tenant, Market $market): int
    {
        if (config('queue.connections.database.connection') !== null && config('queue.connections.database.connection') !== config('database.default')) {
            throw new RuntimeException('queue_must_share_tenant_connection');
        }
        if (config('queue.connections.database.after_commit')) {
            throw new RuntimeException('collection_enqueue_must_commit_atomically');
        }

        return DB::transaction(function () use ($tenant, $market): int {
            $tenant = Team::query()->lock('for no key update')->findOrFail($tenant->id);
            $now = CarbonImmutable::now('UTC');
            $day = $now->setTimezone($tenant->timezone)->startOfDay();
            $batch = RunBatch::query()->firstOrCreate(
                ['market_id' => $market->id, 'dispatch_date' => $day->toDateString()],
                ['scheduled_for' => $now, 'status' => 'queued'],
            );
            if (! $batch->wasRecentlyCreated) {
                return 0;
            }
            $plan = $tenant->plan()->first();
            $candidates = [];
            $prompts = $market->prompts()->where('is_active', true)->orderByRaw("CASE WHEN priority = 'core' THEN 0 ELSE 1 END")->orderBy('id')->get();
            $engines = $market->marketEngines()->with('engine')->orderBy('id')->get()->groupBy('engine_key');
            foreach ($prompts as $prompt) {
                foreach ($engines as $key => $settings) {
                    $setting = ($prompt->priority === 'core' ? $settings->firstWhere('prompt_scope', 'core') : null) ?? $settings->firstWhere('prompt_scope', 'all');
                    if ($setting === null || $setting->frequency === 'off' || ! $setting->engine->isAvailable() || $plan === null || ! $plan->allowsEngine($key)) {
                        continue;
                    }
                    $rank = ['off' => 0, 'monthly' => 1, 'weekly' => 2, 'daily' => 3];
                    $frequency = $rank[$setting->frequency] <= $rank[$plan->maxFrequencyForEngine($key)] ? $setting->frequency : $plan->maxFrequencyForEngine($key);
                    if ($frequency === 'off' || ($prompt->priority !== 'core' && ! PromptSchedule::due($prompt->id, $frequency, $day))) {
                        continue;
                    }
                    $price = $this->prices->effectiveAt($key, $now);
                    $account = $this->keys->forEngine($tenant, $setting->engine) === null ? BillingAccount::Platform : BillingAccount::Byok;
                    // Shared platform Sonnet keeps its budgeted weekly cadence; BYOK has its own budget.
                    if ($key === 'claude_sonnet' && $account === BillingAccount::Platform && app(Account::class)->configured()
                        && ! PromptSchedule::due($prompt->id, $frequency === 'daily' ? 'weekly' : $frequency, $day)) {
                        continue;
                    }
                    for ($repetition = 1; $repetition <= min(3, $setting->repetitions, $plan->max_repetitions); $repetition++) {
                        $run = $batch->runs()->create(['prompt_id' => $prompt->id, 'engine_key' => $key, 'repetition' => $repetition]);
                        $candidates[] = new PlannedRun($run->id, $prompt->priority === 'core', $account, $price->price_per_call_usd);
                    }
                }
            }
            if ($candidates === []) {
                $batch->delete();

                return 0;
            }
            $allowed = $this->quota->allowedRunIds($this->usage->forAdmission($tenant, $now), $candidates);
            foreach ($candidates as $candidate) {
                $run = Run::query()->findOrFail($candidate->id);
                if (! in_array($candidate->id, $allowed, true)) {
                    $run->update(['status' => 'skipped_quota', 'finished_at' => $now]);

                    continue;
                }
                Queue::connection('database')->push(new CollectEngineRun($tenant->id, $run->id));
                $run->forceFill(['enqueued_at' => $now])->save();
            }
            $batch->update(['planned_runs' => count($candidates), 'status' => $allowed === [] ? 'completed' : 'queued']);
            if ($allowed !== []) {
                DB::afterCommit(function (): void {
                    try {
                        app(DispatcherLauncher::class)->start();
                    } catch (Throwable $failure) {
                        report($failure);
                    }
                });
            }

            return count($allowed);
        });
    }
}
