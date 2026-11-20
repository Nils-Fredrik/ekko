<?php

namespace App\AnthropicBudget;

use App\Engines\Credentials\TenantApiKeys;
use App\Enums\TenantStatus;
use App\Models\AnthropicBudgetPlan;
use App\Models\AnthropicInsightAllocation;
use App\Models\Market;
use App\Models\Team;
use App\Scheduling\CollectionLocks;
use App\Scheduling\PromptSchedule;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Planner
{
    public function __construct(private Account $account, private Estimator $estimator, private TenantContext $context, private TenantRls $rls, private TenantApiKeys $keys) {}

    public function plan(CarbonImmutable $now): void
    {
        if (! $this->account->configured()) {
            return;
        }
        $lock = CollectionLocks::make('ekko:anthropic-budget', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('anthropic_budget_busy');
        }
        try {
            $cycle = $this->account->cycle($now);
            $days = $cycle->daysLeft($now);
            $day = $now->utc()->toDateString();
            if (DB::table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->whereDate('planned_at', $day)->exists()) {
                return;
            }
            $baseline = 0;
            $platformPaid = 0;
            $offers = [];
            $tenants = Team::query()->where('personal_team', false)->where('status', TenantStatus::Active->value)->with('plan')->orderBy('id')->get();
            foreach ($tenants as $tenant) {
                $catalog = $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], fn () => $this->catalog($tenant, $now, $days)));
                $baseline += $catalog['baseline'];
                $platformPaid += $catalog['platform_paid'];
                array_push($offers, ...$catalog['offers']);
            }
            // Rotate ties daily across tenants. Variance has priority only within the repetition tier.
            usort($offers, fn ($a, $b) => ($a['tier'] <=> $b['tier']) ?: ($b['variance'] <=> $a['variance']) ?: ($a['repetition'] <=> $b['repetition']) ?: (strcmp(hash('sha256', $day.$a['key']), hash('sha256', $day.$b['key']))));
            $spent = $this->account->spent($cycle);
            $held = Money::units((string) DB::table('anthropic_budget_holds')->where('cycle_start', $cycle->start->toDateString())->whereNull('settled_at')->sum('cost_usd'));
            $forecast = app(Allocator::class)->allocate($this->account->pool(), $spent, $baseline, $days, $offers, $this->account->exhausted($cycle), $held);
            foreach ($tenants as $tenant) {
                $additions = array_values(array_filter($forecast['additions'], fn ($offer) => $offer['tenant'] === $tenant->id));
                $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], function () use ($additions, $day, $cycle): void {
                    foreach ($additions as $item) {
                        if ($item['kind'] !== 'insight' || ! isset($item['brand'])) {
                            continue;
                        }
                        $operation = 'insight:'.$item['brand'].':'.$cycle->start->toDateString();
                        if (! $this->account->reserve($operation, $item['cost'], false)) {
                            continue;
                        }
                        AnthropicInsightAllocation::query()->firstOrCreate(['brand_id' => $item['brand'], 'cycle_start' => $cycle->start->toDateString()], ['cost_usd' => Money::usd($item['cost'])]);
                    }
                    AnthropicBudgetPlan::query()->updateOrCreate(['day' => $day], ['additions' => $additions]);
                }));
            }
            $counts = array_count_values(array_column($forecast['additions'], 'kind'));
            $public = ['forecast_usd' => Money::usd($forecast['forecast']), 'buffer_usd' => Money::usd($forecast['buffer']), 'available_usd' => Money::usd($forecast['available']), 'held_usd' => Money::usd($held), 'platform_paid_usd' => Money::usd($platformPaid), 'additions' => $counts];
            DB::table('anthropic_budget_cycles')->upsert([['cycle_start' => $cycle->start->toDateString(), 'forecast' => json_encode($public, JSON_THROW_ON_ERROR), 'planned_at' => $now]], ['cycle_start'], ['forecast', 'planned_at']);
        } finally {
            $lock->release();
        }
    }

    /**
     * The baseline includes the tenant's expected platform-paid helper calls (platform_paid, also on its own), which
     * are outside its quota but draw on the same account (ADR 0034).
     *
     * @return array{baseline: int, platform_paid: int, offers: list<array{key: string, kind: string, cost: int, tier: int, variance: float, tenant: int, prompt: int, engine: string, repetition: int, brand?: int}>}
     */
    private function catalog(Team $tenant, CarbonImmutable $now, int $days): array
    {
        if ($tenant->kind === 'pitch') {
            // The daily dispatcher runs at this UTC time; exclude dates after expiry.
            $eligibleDays = 0;
            for ($offset = 0; $offset < $days; $offset++) {
                if ($tenant->mayCollectAt($now->addDays($offset))) {
                    $eligibleDays++;
                }
            }
            $days = $eligibleDays;
        }
        $platformPaid = $this->estimator->platformPaid($now, $days);
        $baseline = $platformPaid;
        $offers = [];
        if ($days === 0) {
            return ['baseline' => 0, 'platform_paid' => 0, 'offers' => []];
        }
        $plan = $tenant->plan;
        if ($plan === null) {
            return ['baseline' => $baseline, 'platform_paid' => $platformPaid, 'offers' => $offers];
        }
        $extractionCost = $this->estimator->expectedExtraction($now);
        $local = $now->setTimezone($tenant->timezone)->startOfDay();
        foreach (Market::query()->where('is_active', true)->whereHas('brand', fn ($q) => $q->where('status', 'active'))->with(['marketEngines.engine', 'prompts' => fn ($q) => $q->where('is_active', true)])->get() as $market) {
            $sonnet = $market->marketEngines->where('frequency', '!=', 'off')->firstWhere('engine_key', 'claude_sonnet');
            if ($sonnet !== null && $sonnet->engine->isAvailable() && $plan->allowsEngine('claude_sonnet') && $plan->maxFrequencyForEngine('claude_sonnet') !== 'off' && $this->keys->forEngine($tenant, $sonnet->engine) === null && ! AnthropicInsightAllocation::query()->where('brand_id', $market->brand_id)->where('cycle_start', $this->account->cycle($now)->start->toDateString())->exists()) {
                $key = $tenant->id.':insight:'.$market->brand_id;
                $offers[$key] = ['key' => $key, 'kind' => 'insight', 'cost' => Money::units((string) config('ekko.anthropic.insight_estimate_usd')), 'tier' => 3, 'variance' => 0.0, 'tenant' => $tenant->id, 'prompt' => 0, 'engine' => 'claude_sonnet', 'repetition' => 1, 'brand' => $market->brand_id];
            }
            foreach ($market->prompts as $prompt) {
                foreach ($market->marketEngines->groupBy('engine_key') as $key => $settings) {
                    if ($tenant->kind === 'pitch' && ! in_array($key, ['chatgpt_api', 'gemini_api', 'claude_haiku'], true)) {
                        continue;
                    }
                    $core = $prompt->priority === 'core';
                    $setting = ($core ? $settings->firstWhere('prompt_scope', 'core') : null) ?? $settings->firstWhere('prompt_scope', 'all');
                    if ($setting === null || ! $setting->engine->isAvailable() || ! $plan->allowsEngine($key)) {
                        continue;
                    }
                    $frequency = PromptSchedule::capped($setting->frequency, $plan->maxFrequencyForEngine($key));
                    if ($tenant->kind === 'pitch' && $frequency !== 'off' && $plan->maxFrequencyForEngine($key) === 'daily') {
                        $frequency = 'daily';
                    }
                    $platform = $this->keys->forEngine($tenant, $setting->engine) === null;
                    $claude = in_array($key, ['claude_haiku', 'claude_sonnet'], true) && $platform;
                    if ($claude && $key === 'claude_sonnet' && $frequency === 'daily') {
                        $frequency = 'weekly';
                    }
                    $repetitions = min(3, $setting->repetitions, $plan->max_repetitions);
                    $cost = $extractionCost;
                    if ($claude) {
                        $cost += $this->estimator->engine($key, $now);
                    }
                    for ($offset = 0; $offset < $days; $offset++) {
                        if (PromptSchedule::due($prompt->id, $frequency, $local->addDays($offset))) {
                            $baseline += $cost * $repetitions;
                        }
                    }
                    if (! $claude || $frequency === 'off') {
                        continue;
                    }
                    $offer = ['key' => $tenant->id.':'.$prompt->id.':'.$key, 'tenant' => $tenant->id, 'prompt' => $prompt->id, 'engine' => $key, 'cost' => $cost, 'repetition' => 1, 'variance' => 0.0];
                    $due = PromptSchedule::due($prompt->id, $frequency, $local);
                    if ($key === 'claude_sonnet' && $setting->frequency === 'daily' && $core && ! $due && $plan->maxFrequencyForEngine($key) === 'daily') {
                        $offers[] = array_replace($offer, ['kind' => 'sonnet_daily', 'tier' => 1, 'cost' => $cost * $repetitions]);
                    }
                    if (! $due || $repetitions >= min(3, $plan->max_repetitions)) {
                        continue;
                    }
                    $observations = DB::table('responses')->join('runs', 'runs.id', '=', 'responses.run_id')
                        ->where('runs.prompt_id', $prompt->id)->where('responses.engine_key', $key)
                        ->where('responses.collected_at', '>=', $now->subDays(14))->where('responses.collected_at', '<', $now)
                        ->selectRaw("count(*) AS n, sum(CASE WHEN EXISTS (SELECT 1 FROM mentions WHERE mentions.response_id = responses.id AND entity_type = 'brand' AND entity_id = ?) THEN 1 ELSE 0 END) AS mentioned", [$market->brand_id])->first();
                    $n = (int) $observations->n;
                    $p = $n > 1 ? (int) $observations->mentioned / $n : 0.0;
                    $variance = $p * (1 - $p);
                    if ($variance <= 0) {
                        continue;
                    }
                    for ($rep = $repetitions + 1; $rep <= min(3, $plan->max_repetitions); $rep++) {
                        $offers[] = array_replace($offer, ['key' => $offer['key'].':'.$rep, 'kind' => 'repetition', 'tier' => 2, 'variance' => $variance, 'repetition' => $rep]);
                    }
                }
            }
        }

        return ['baseline' => $baseline, 'platform_paid' => $platformPaid, 'offers' => array_values($offers)];
    }
}
