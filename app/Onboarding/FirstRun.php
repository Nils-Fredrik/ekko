<?php

namespace App\Onboarding;

use App\AnthropicBudget\Account;
use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EnginePricing;
use App\Jobs\CollectEngineRun;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Scheduling\PromptSchedule;
use App\Scheduling\WorkerLauncher;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Starts the first run of a confirmed market immediately, for the core prompts that are due today
 * (docs/spec.md 13). Quota, platform Claude cadence and the locked outbox match the ordinary dispatcher.
 * Later runs follow the ordinary schedule.
 */
final class FirstRun
{
    public function __construct(
        private EnginePricing $prices,
        private TenantApiKeys $keys,
        private MonthlyUsage $usage,
        private QuotaGuard $quota,
    ) {}

    public function start(Team $tenant, Market $market): int
    {
        $now = CarbonImmutable::now('UTC');
        $queued = $this->plan($tenant, $market, $now);
        $count = 0;

        foreach ($queued as $id) {
            try {
                DB::transaction(function () use ($tenant, $id, $now, &$count): void {
                    $run = Run::query()->whereKey($id)->lockForUpdate()->first();
                    if ($run === null || $run->status !== 'queued' || $run->enqueued_at !== null) {
                        return;
                    }
                    Queue::connection('database')->push(new CollectEngineRun($tenant->id, $run->id));
                    $run->forceFill(['enqueued_at' => $now])->save();
                    $count++;
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        // The confirm request holds a transaction until the response (ApplyTenantRls). The jobs above are
        // invisible to the worker until that commits, so the launch waits for the commit.
        DB::afterCommit(function (): void {
            try {
                app(WorkerLauncher::class)->startIfPending();
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $count;
    }

    /**
     * Creates today's runs and marks the ones that do not fit as skipped. Enqueue happens afterwards, one locked
     * row at a time, so a concurrent dispatcher cannot push the same run.
     *
     * @return list<int>
     */
    private function plan(Team $tenant, Market $market, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($tenant, $market, $now): array {
            $day = $now->setTimezone($tenant->timezone)->startOfDay();
            $batch = RunBatch::query()->firstOrCreate(
                ['market_id' => $market->id, 'dispatch_date' => $day->toDateString()],
                ['scheduled_for' => $day->utc(), 'status' => 'queued'],
            );
            if (! $batch->wasRecentlyCreated) {
                return $batch->runs()->where('status', 'queued')->whereNull('enqueued_at')->orderBy('id')->pluck('id')->all();
            }

            $plan = $tenant->plan;
            $budget = app(Account::class);
            $prompts = $market->prompts()->where('is_active', true)->where('priority', 'core')->orderBy('id')->get();
            $settings = $market->marketEngines()->with('engine')->where('frequency', '!=', 'off')->orderBy('id')->get();
            $candidates = [];

            foreach ($prompts as $prompt) {
                foreach ($settings as $setting) {
                    $key = (string) $setting->engine_key;
                    if ($setting->engine === null || ! $setting->engine->isAvailable() || ($plan instanceof Plan && ! $plan->allowsEngine($key))) {
                        continue;
                    }
                    $frequency = $this->capped($setting->frequency, $plan instanceof Plan ? $plan->maxFrequencyForEngine($key) : $setting->frequency);
                    $platformClaude = $budget->configured()
                        && in_array($key, ['claude_haiku', 'claude_sonnet'], true)
                        && $this->keys->forEngine($tenant, $setting->engine) === null;
                    if ($platformClaude && $key === 'claude_sonnet' && $frequency === 'daily') {
                        $frequency = 'weekly';
                    }
                    if (! PromptSchedule::due($prompt->id, $frequency, $day)) {
                        continue;
                    }
                    $price = $this->prices->effectiveAt($key, $now);
                    $account = $this->keys->forEngine($tenant, $setting->engine) === null ? BillingAccount::Platform : BillingAccount::Byok;
                    $repetitions = min(3, (int) $setting->repetitions, $plan instanceof Plan ? $plan->max_repetitions : 3);
                    for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
                        $run = $batch->runs()->create([
                            'prompt_id' => $prompt->id,
                            'engine_key' => $key,
                            'repetition' => $repetition,
                            'status' => 'queued',
                            'attempts' => 0,
                        ]);
                        $candidates[] = new PlannedRun($run->id, true, $account, (string) $price->price_per_call_usd);
                    }
                }
            }

            $batch->update(['planned_runs' => $batch->runs()->count()]);
            $allowed = array_flip($this->quota->allowedRunIds($this->usage->forAdmission($tenant, $now), $candidates));
            $queued = [];
            foreach ($candidates as $candidate) {
                if (! isset($allowed[$candidate->id])) {
                    Run::query()->whereKey($candidate->id)->update(['status' => 'skipped_quota', 'finished_at' => $now]);

                    continue;
                }
                $queued[] = $candidate->id;
            }

            return $queued;
        });
    }

    private function capped(string $chosen, string $max): string
    {
        $rank = ['off' => 0, 'monthly' => 1, 'weekly' => 2, 'daily' => 3];

        return ($rank[$chosen] ?? 0) <= ($rank[$max] ?? 0) ? $chosen : $max;
    }
}
