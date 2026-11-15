<?php

namespace App\AnthropicBudget;

use App\Engines\EnginePricing;
use App\Engines\EngineResult;
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

    public function extraction(int $inputBytes = 12000, bool $batch = true): int
    {
        // One token per byte is deliberately conservative for mixed-language input.
        $input = Money::units((string) config('ekko.anthropic.extraction.input_usd_per_mtok'));
        $output = Money::units((string) config('ekko.anthropic.extraction.output_usd_per_mtok'));
        $cost = (int) ceil(($input * $inputBytes + $output * (int) config('ekko.anthropic.extraction.max_tokens')) / 1_000_000);

        return $batch ? (int) ceil($cost * (float) config('ekko.anthropic.extraction.batch_price_factor')) : $cost;
    }
}
