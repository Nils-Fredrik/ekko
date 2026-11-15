<?php

namespace App\Engines;

use App\Models\EnginePrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

final class EnginePricing
{
    public function effectiveAt(string $engineKey, CarbonImmutable $occurredAt): EnginePrice
    {
        return EnginePrice::query()
            ->where('engine_key', $engineKey)
            ->where('valid_from', '<=', $occurredAt)
            ->orderByDesc('valid_from')
            ->firstOrFail();
    }

    public function cost(EnginePrice $price, EngineResult $result): string
    {
        $inputCost = BigDecimal::of($price->price_per_1k_input_tokens_usd)
            ->multipliedBy($result->inputTokens)->dividedByExact(1000);
        $outputCost = BigDecimal::of($price->price_per_1k_output_tokens_usd)
            ->multipliedBy($result->outputTokens)->dividedByExact(1000);
        $searchCost = BigDecimal::of($price->price_per_search_usd)->multipliedBy($result->searchCalls);

        return (string) BigDecimal::of($price->price_per_call_usd)
            ->plus($inputCost)
            ->plus($outputCost)
            ->plus($searchCost)
            ->toScale(8, RoundingMode::HalfUp);
    }
}
