<?php

namespace App\AnthropicBudget;

use App\Engines\EnginePricing;
use App\Engines\EngineResult;
use App\Models\UsageEvent;
use App\Usage\PlatformPaidUsage;
use Carbon\CarbonImmutable;

final class Estimator
{
    public function engine(string $key, CarbonImmutable $at, int $outputTokens = 800): int
    {
        $pricing = app(EnginePricing::class);

        return Money::units($pricing->cost($pricing->effectiveAt($key, $at), new EngineResult(
            answerText: '', modelIdReported: null, citations: [], inputTokens: 15000, outputTokens: $outputTokens, searchCalls: 2, raw: [],
        )));
    }

    public function expectedExtraction(CarbonImmutable $at): int
    {
        $mean = UsageEvent::query()->where('kind', 'extraction')->where('billed_to', 'platform')
            ->where('occurred_at', '>=', $at->subDays(14))->where('occurred_at', '<', $at)
            ->selectRaw('sum(cost_usd) / nullif(sum(calls), 0) AS mean')->value('mean');

        return $mean === null ? $this->extraction(3000, true, 800) : Money::units((string) $mean);
    }

    /**
     * Expected platform-paid helper calls (ekko.usage.platform_paid_kinds, ADR 0034) for the rest of the cycle, for
     * the current tenant: the mean daily cost of the last 14 days times the days left. They are outside the tenant's
     * quota, but they draw on the Anthropic account, so the forecast includes them.
     */
    public function platformPaid(CarbonImmutable $at, int $days): int
    {
        $sum = PlatformPaidUsage::only(UsageEvent::query())->where('billed_to', 'platform')
            ->where('occurred_at', '>=', $at->subDays(14))->where('occurred_at', '<', $at)
            ->sum('cost_usd');

        return (int) ceil(Money::units((string) $sum) * max(0, $days) / 14);
    }

    public function extraction(int $inputBytes = 12000, bool $batch = true, ?int $outputTokens = null): int
    {
        // One token per byte is deliberately conservative for mixed-language input.
        $input = Money::units((string) config('ekko.anthropic.extraction.input_usd_per_mtok'));
        $output = Money::units((string) config('ekko.anthropic.extraction.output_usd_per_mtok'));
        $cost = (int) ceil(($input * $inputBytes + $output * ($outputTokens ?? (int) config('ekko.anthropic.extraction.max_tokens'))) / 1_000_000);

        return $batch ? (int) ceil($cost * (float) config('ekko.anthropic.extraction.batch_price_factor')) : $cost;
    }
}
