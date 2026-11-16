<?php

namespace App\Scheduling;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Planner;
use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EnginePricing;
use App\Enums\TenantStatus;
use App\Jobs\CollectEngineRun;
use App\Jobs\SubmitExtractionBatch;
use App\Models\AnthropicBudgetPlan;
use App\Models\Market;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

final class Dispatcher
{
    public int $failures = 0;

    private bool $budgetPlanned = false;

    public function __construct(private TenantContext $context, private TenantRls $rls, private EnginePricing $prices, private TenantApiKeys $keys, private MonthlyUsage $usage, private QuotaGuard $quota) {}

    /** Planning is idempotent per tenant-local date; enqueue and marker commit together in PostgreSQL. */
    public function dispatch(CarbonImmutable $now, bool $plan = true): int
    {
        $this->failures = 0;
        $this->budgetPlanned = false;
        if ($plan) {
            try {
                app(Planner::class)->plan($now);
                $this->budgetPlanned = true;
            } catch (Throwable $failure) {
                $this->failures++;
                Log::error('Anthropic budget planning failed; dispatching without additions.', ['error_type' => $failure::class]);
            }
        }
        if (config('queue.connections.database.connection') !== null && config('queue.connections.database.connection') !== config('database.default')) {
            throw new RuntimeException('queue_must_share_tenant_connection');
        }
        if (config('queue.connections.database.after_commit')) {
            throw new RuntimeException('collection_enqueue_must_commit_atomically');
        }
        $lock = CollectionLocks::make('ekko:dispatch', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('dispatcher_busy');
        }
        try {
            $tenants = Team::query()->where('personal_team', false)->where('status', TenantStatus::Active->value)->orderBy('id')->get();
            $pending = [];
            foreach ($tenants as $tenant) {
                try {
                    $pending[$tenant->id] = $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], function () use ($tenant, $now, $plan) {
                        $tenant = Team::query()->lock('for no key update')->findOrFail($tenant->id);
                        if ($plan) {
                            $this->plan($tenant, $now);
                        }

                        $this->refreshBatches();
                        if (Run::query()->where('status', 'collected')->exists()) {
                            SubmitExtractionBatch::dispatch($tenant->id)->onConnection('database')->afterCommit();
                        }

                        return Run::query()->whereNull('enqueued_at')->where(function ($query) use ($now) {
                            $query->where('status', 'queued')->orWhere(fn ($poll) => $poll->where('status', 'submitted')->where('next_poll_at', '<=', $now));
                        })->orderBy('id')->pluck('id')->all();
                    }));
                } catch (Throwable $failure) {
                    $this->failures++;
                    $pending[$tenant->id] = [];
                    Log::error('Tenant dispatch planning failed.', ['tenant_id' => $tenant->id, 'error_type' => $failure::class]);
                }
            }
            $count = 0;
            foreach (FairQueue::interleave($pending) as $entry) {
                $id = $entry['tenant_id'];
                try {
                    $count += $this->context->run($id, fn () => $this->rls->run([$id], function () use ($entry, $now, $id): int {
                        $run = Run::query()->lockForUpdate()->findOrFail($entry['item']);
                        if ($run->enqueued_at !== null || ! in_array($run->status, ['queued', 'submitted'], true)) {
                            return 0;
                        }
                        // Explicit connection: synchronous/failover dispatch would break the atomic outbox.
                        Queue::connection('database')->push(new CollectEngineRun($id, $run->id));
                        $run->forceFill(['enqueued_at' => $now])->save();

                        return 1;
                    }));
                } catch (Throwable $failure) {
                    $this->failures++;
                    Log::error('Run enqueue failed.', ['tenant_id' => $id, 'run_id' => $entry['item'], 'error_type' => $failure::class]);
                }
            }

            return $count;
        } finally {
            $lock->release();
        }
    }

    private function refreshBatches(): void
    {
        foreach (RunBatch::query()->whereIn('status', ['queued', 'running'])->get() as $batch) {
            $counts = $batch->runs()->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');
            $complete = (int) ($counts['collected'] ?? 0) + (int) ($counts['extracted'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);
            $remaining = (int) ($counts['queued'] ?? 0) + (int) ($counts['running'] ?? 0) + (int) ($counts['submitted'] ?? 0);
            $batch->update(['completed_runs' => $complete, 'failed_runs' => $failed, 'status' => $remaining === 0 ? 'completed' : 'running']);
        }
    }

    private function plan(Team $tenant, CarbonImmutable $now): void
    {
        $plan = $tenant->plan;
        if ($plan === null) {
            return;
        }
        $day = $now->setTimezone($tenant->timezone)->startOfDay();
        $candidates = [];
        $budget = app(Account::class);
        $additions = $this->budgetPlanned && $budget->configured() ? (AnthropicBudgetPlan::query()->where('day', $now->utc()->toDateString())->first()->additions ?? []) : [];
        foreach (Market::query()->where('is_active', true)->whereHas('brand', fn ($q) => $q->where('status', 'active'))->with(['marketEngines.engine', 'prompts' => fn ($q) => $q->where('is_active', true)->orderByRaw("CASE WHEN priority = 'core' THEN 0 ELSE 1 END")->orderBy('id')])->orderBy('id')->get() as $market) {
            $batch = RunBatch::query()->firstOrCreate(['market_id' => $market->id, 'dispatch_date' => $day->toDateString()], ['scheduled_for' => $day->utc(), 'status' => 'queued']);
            if (! $batch->wasRecentlyCreated) {
                continue;
            }
            foreach ($market->prompts as $prompt) {
                foreach ($market->marketEngines->groupBy('engine_key') as $key => $settings) {
                    $setting = ($prompt->priority === 'core' ? $settings->firstWhere('prompt_scope', 'core') : null) ?? $settings->firstWhere('prompt_scope', 'all');
                    if ($setting === null || ! $setting->engine->is_enabled || ! $plan->allowsEngine($key)) {
                        continue;
                    }
                    $ranks = ['off' => 0, 'monthly' => 1, 'weekly' => 2, 'daily' => 3];
                    $frequency = $ranks[$setting->frequency] <= $ranks[$plan->maxFrequencyForEngine($key)] ? $setting->frequency : $plan->maxFrequencyForEngine($key);
                    $platformClaude = $budget->configured() && in_array($key, ['claude_haiku', 'claude_sonnet'], true) && $this->keys->forEngine($tenant, $setting->engine) === null;
                    if ($platformClaude && $key === 'claude_sonnet' && $frequency === 'daily') {
                        $frequency = 'weekly';
                    }
                    $extra = array_values(array_filter($additions, fn ($item) => $item['prompt'] === $prompt->id && $item['engine'] === $key));
                    $dailyExtra = $setting->frequency === 'daily' && $plan->maxFrequencyForEngine($key) === 'daily' && count(array_filter($extra, fn ($item) => $item['kind'] === 'sonnet_daily')) > 0;
                    if (! PromptSchedule::due($prompt->id, $frequency, $day) && ! $dailyExtra) {
                        continue;
                    }
                    $price = $this->prices->effectiveAt($key, $now);
                    $account = $this->keys->forEngine($tenant, $setting->engine) === null ? BillingAccount::Platform : BillingAccount::Byok;
                    $repetitions = min(3, $setting->repetitions, $plan->max_repetitions);
                    foreach ($extra as $item) {
                        $repetitions = max($repetitions, min(3, $plan->max_repetitions, $item['repetition']));
                    }
                    for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
                        $run = $batch->runs()->create(['prompt_id' => $prompt->id, 'engine_key' => $key, 'repetition' => $repetition]);
                        $candidates[] = new PlannedRun($run->id, $prompt->priority === 'core', $account, $price->price_per_call_usd);
                    }
                }
            }
            $batch->update(['planned_runs' => $batch->runs()->count()]);
        }
        // Prioritize core prompts across all markets, not just inside each market.
        usort($candidates, fn ($a, $b) => ($b->core <=> $a->core) ?: ($a->id <=> $b->id));
        $allowed = $this->quota->allowedRunIds($this->usage->forAdmission($tenant, $now), $candidates);
        foreach ($candidates as $candidate) {
            if (! in_array($candidate->id, $allowed, true)) {
                Run::query()->whereKey($candidate->id)->update(['status' => 'skipped_quota', 'finished_at' => $now]);
            }
        }
    }
}
