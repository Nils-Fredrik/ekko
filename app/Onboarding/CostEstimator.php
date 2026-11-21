<?php

namespace App\Onboarding;

use App\AnthropicBudget\Account;
use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EnginePricing;
use App\Engines\EngineResult;
use App\Extraction\ExtractionCost;
use App\Extraction\ExtractionOutcome;
use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\PromptPopulation;
use App\MeasurementPlan\StandardPlan;
use App\Models\Engine;
use App\Models\Plan;
use App\Models\RunBatch;
use App\Models\Team;
use App\Scheduling\PromptSchedule;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Monthly baseline for an unsaved plan. Quotas and budgets are shared across all markets (spec 7.6).
 *
 * @phpstan-import-type PlanRule from MeasurementPlans
 *
 * @phpstan-type EstimateMarket array{id: ?int, plan_mode: string, is_active: bool, engines: list<PlanRule>, prompts: PromptPopulation}
 * @phpstan-type Quote array{available: bool, price: ?BigDecimal, payer: BillingAccount, usage: array<string,int>}
 * @phpstan-type EstimateRow array{engine_key: string, answers: int, billed_to: string, collection_cost_usd: ?string, frequency: string, prompt_scope: string, repetitions: int, sample_prompt_id: ?int, next_due_on: ?string, usage_per_answer: array<string, int>}
 */
final class CostEstimator
{
    public function __construct(
        private readonly EnginePricing $pricing,
        private readonly TenantApiKeys $keys,
        private readonly ExtractionCost $extraction,
        private readonly MonthlyUsage $usage,
    ) {}

    /**
     * @param  list<EstimateMarket>  $markets
     * @return array<string, mixed>
     */
    public function estimate(Team $tenant, array $markets, bool $legacyProposal = false): array
    {
        $at = CarbonImmutable::now('UTC');
        $localDay = $at->setTimezone($tenant->timezone)->startOfDay();
        $planned = RunBatch::query()->whereIn('market_id', array_column($markets, 'id'))
            ->where('dispatch_date', $localDay->toDateString())->pluck('market_id')->flip();
        $plan = $tenant->plan()->first();
        $config = config('ekko.wizard.estimate');
        $extractionUsage = $config['extraction_usage'];
        $extractionPrice = BigDecimal::of($this->extraction->of(new ExtractionOutcome('estimate', true, null, $extractionUsage['input_tokens'], $extractionUsage['output_tokens'], true)));
        $catalog = [];
        $estimates = [];
        $allRows = [];
        $issues = [];

        foreach ($markets as $market) {
            $population = $market['prompts'];
            $marketIssues = [];
            $rows = [];
            if ($market['is_active']) {
                if ($plan?->max_prompts_per_market !== null && $population->total > $plan->max_prompts_per_market) {
                    $marketIssues[] = ['code' => 'prompts_above_plan'];
                }
                $coreOverrides = array_column(array_filter($market['engines'], fn ($rule) => $rule['prompt_scope'] === 'core'), 'engine_key');
                $from = $market['id'] !== null && $planned->has($market['id']) ? $localDay->addDay() : $localDay;
                foreach ($market['engines'] as $rule) {
                    $key = $rule['engine_key'];
                    $catalog[$key] ??= $this->quote($key, $tenant, $at);
                    $quote = $catalog[$key];
                    [$frequency, $repetitions] = $this->baseline($tenant, $plan, $rule, $quote, $legacyProposal, $marketIssues);
                    [$count, $example] = match (true) {
                        $rule['prompt_scope'] === 'core' => [$population->core, $population->coreExample],
                        in_array($key, $coreOverrides, true) => [$population->total - $population->core, $population->standardExample],
                        default => [$population->total, $population->standardExample ?? $population->coreExample],
                    };
                    $answers = BigDecimal::of($count * $repetitions)->multipliedBy($config['runs_per_month'][$frequency])->toScale(0, RoundingMode::Up)->toInt();
                    $cost = $frequency === 'off' ? BigDecimal::zero() : $quote['price']?->multipliedBy($answers);
                    $rows[] = [
                        'engine_key' => $key,
                        'prompt_scope' => $rule['prompt_scope'],
                        'frequency' => $frequency,
                        'repetitions' => $repetitions,
                        'answers' => $answers,
                        'billed_to' => $quote['payer']->value,
                        'collection_cost_usd' => $cost?->__toString(),
                        'sample_prompt_id' => $frequency === 'off' || $count === 0 ? null : $example,
                        'next_due_on' => $count > 0 ? $this->nextDue($frequency, $example, $from) : null,
                        'usage_per_answer' => $quote['usage'],
                    ];
                }
            }
            foreach (array_unique($marketIssues, SORT_REGULAR) as $issue) {
                $issue['message'] = __("onboarding.issues.{$issue['code']}", ['engine' => $issue['engine_key'] ?? '']);
                $issues[] = $issue + ['market_id' => $market['id']];
            }
            $estimates[] = [
                'id' => $market['id'],
                'plan_mode' => $market['plan_mode'],
                'is_active' => $market['is_active'],
                'prompt_count' => $population->total,
                'core_prompt_count' => $population->core,
                'rules' => $rows,
                'engines' => $this->byEngine($rows, $extractionPrice),
            ] + $this->totals($rows, $extractionPrice);
            array_push($allRows, ...$rows);
        }

        $totals = $this->totals($allRows, $extractionPrice);
        $precise = $this->totals($allRows, $extractionPrice, 8);
        $usage = $this->usage->snapshot($tenant, $at);
        $remaining = $usage->quota_calls === null ? null : max(0, $usage->quota_calls - $usage->calls);
        $warnings = [
            'exceeds_call_quota' => $remaining !== null && $usage->calls + $totals['quota_calls'] > $usage->quota_calls,
            'exceeds_budget' => $usage->budget_usd !== null && $precise['platform_cost_usd'] !== null && BigDecimal::of($usage->cost_usd)->plus($precise['platform_cost_usd'])->isGreaterThan($usage->budget_usd),
            'exceeds_byok_budget' => $usage->byok_budget_usd !== null && $precise['byok_cost_usd'] !== null && BigDecimal::of($usage->byok_cost_usd)->plus($precise['byok_cost_usd'])->isGreaterThan($usage->byok_budget_usd),
        ];
        foreach (array_keys(array_filter($warnings)) as $code) {
            $issues[] = ['code' => $code, 'message' => __("onboarding.issues.{$code}")];
        }

        return $totals + [
            'markets' => $estimates,
            'engines' => $this->byEngine($allRows, $extractionPrice),
            'issues' => $issues,
            'quota' => ['limit' => $usage->quota_calls, 'used' => $usage->calls, 'remaining' => $remaining],
            'budgets' => [
                'platform' => ['limit_usd' => $usage->budget_usd, 'used_usd' => $usage->cost_usd],
                'byok' => ['limit_usd' => $usage->byok_budget_usd, 'used_usd' => $usage->byok_cost_usd],
            ],
            'assumptions' => [
                'runs_per_month' => $config['runs_per_month'],
                'extraction_usage' => $extractionUsage,
                'batch_extraction_usd_per_answer' => (string) $extractionPrice,
                'extractions_per_answer' => 1,
            ],
        ];
    }

    /**
     * The original wizard reports an invalid proposal at its requested rate. For a stored market whose package
     * has changed, the baseline follows the dispatcher's package caps, pitch rules and shared Sonnet credits.
     *
     * @param  PlanRule  $rule
     * @param  Quote  $quote
     * @param  list<array{code: string, engine_key?: string}>  $issues
     * @return array{string, int}
     */
    private function baseline(Team $tenant, ?Plan $plan, array $rule, array $quote, bool $legacyProposal, array &$issues): array
    {
        $key = $rule['engine_key'];
        $frequency = $rule['frequency'];
        $repetitions = $rule['repetitions'];
        if ($frequency === 'off') {
            return [$frequency, $repetitions];
        }
        if (! $quote['available']) {
            $issues[] = ['code' => 'unknown_engine', 'engine_key' => $key];

            return ['off', $repetitions];
        }

        $allowed = $plan?->allowsEngine($key) && ($tenant->kind !== 'pitch' || in_array($key, StandardPlan::PITCH_ENGINES, true));
        $maximum = $plan?->maxFrequencyForEngine($key) ?? 'off';
        if (! $allowed) {
            $issues[] = ['code' => 'engine_not_in_plan', 'engine_key' => $key];
        }
        if (! PromptSchedule::allows($frequency, $maximum)) {
            $issues[] = ['code' => 'frequency_above_plan', 'engine_key' => $key];
        }
        if ($plan !== null && $repetitions > $plan->max_repetitions) {
            $issues[] = ['code' => 'repetitions_above_plan', 'engine_key' => $key];
        }
        if ($quote['price'] === null) {
            $issues[] = ['code' => 'no_price', 'engine_key' => $key];
        }

        if (! $legacyProposal) {
            $frequency = $allowed ? PromptSchedule::capped($frequency, $maximum) : 'off';
            $repetitions = min($repetitions, $plan->max_repetitions ?? 1);
        }
        if ($tenant->kind === 'pitch' && $frequency !== 'off' && $maximum === 'daily' && $allowed) {
            $frequency = 'daily';
        }
        if ($key === 'claude_sonnet' && $frequency === 'daily' && $quote['payer'] === BillingAccount::Platform && app(Account::class)->configured()) {
            $frequency = 'weekly';
        }

        return [$frequency, $repetitions];
    }

    /** @return Quote */
    private function quote(string $key, Team $tenant, CarbonImmutable $at): array
    {
        $engine = Engine::query()->find($key);
        $usage = config('ekko.wizard.estimate.call_usage')[$engine?->method] ?? ['input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0];
        $price = null;
        if ($engine?->isAvailable()) {
            try {
                $price = BigDecimal::of($this->pricing->cost($this->pricing->effectiveAt($key, $at), new EngineResult('', null, [], $usage['input_tokens'], $usage['output_tokens'], $usage['search_calls'], [])));
            } catch (ModelNotFoundException) {
                // Show the rule and its volume so the missing price can be fixed before confirmation.
            }
        }

        return ['available' => $engine?->isAvailable() ?? false, 'price' => $price, 'usage' => $usage,
            'payer' => $engine !== null && $this->keys->forEngine($tenant, $engine) !== null ? BillingAccount::Byok : BillingAccount::Platform];
    }

    private function nextDue(string $frequency, ?int $example, CarbonImmutable $from): ?string
    {
        if ($example === null || $frequency === 'off') {
            return null;
        }
        $date = $from;
        while (! PromptSchedule::due($example, $frequency, $date)) {
            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    /**
     * @param  list<EstimateRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function byEngine(array $rows, BigDecimal $extractionPrice): array
    {
        $engines = [];
        foreach (collect($rows)->groupBy('engine_key') as $key => $rules) {
            $engines[] = ['engine_key' => $key, 'billed_to' => $rules->first()['billed_to']] + $this->totals($rules->values()->all(), $extractionPrice);
        }

        return $engines;
    }

    /**
     * @param  list<EstimateRow>  $rows
     * @return array{answers:int, quota_calls:int, engine_cost_usd:?string, extraction_cost_usd:string, total_cost_usd:?string, platform_cost_usd:?string, byok_cost_usd:?string}
     */
    private function totals(array $rows, BigDecimal $extractionPrice, int $scale = 2): array
    {
        $answers = array_sum(array_column($rows, 'answers'));
        $costs = ['platform' => BigDecimal::zero(), 'byok' => BigDecimal::zero()];
        foreach ($rows as $row) {
            $payer = $row['billed_to'];
            $costs[$payer] = $row['collection_cost_usd'] === null ? null : $costs[$payer]?->plus($row['collection_cost_usd']);
        }
        $platform = $costs['platform']?->toScale($scale, RoundingMode::HalfUp);
        $byok = $costs['byok']?->toScale($scale, RoundingMode::HalfUp);
        $collection = $byok === null ? null : $platform?->plus($byok);
        $extraction = $extractionPrice->multipliedBy($answers)->toScale($scale, RoundingMode::HalfUp);

        return [
            'answers' => $answers,
            'quota_calls' => 2 * $answers,
            'engine_cost_usd' => $collection?->__toString(),
            'extraction_cost_usd' => (string) $extraction,
            'total_cost_usd' => $collection?->plus($extraction)->__toString(),
            'platform_cost_usd' => $platform?->plus($extraction)->__toString(),
            'byok_cost_usd' => $byok?->__toString(),
        ];
    }
}
