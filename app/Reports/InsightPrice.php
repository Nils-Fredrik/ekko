<?php

namespace App\Reports;

use App\Engines\EnginePricing;
use App\Engines\EngineResult;
use App\Models\EnginePrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

/** Prices a known Sonnet response. A pricing failure still returns a charge (ADR 0023). */
class InsightPrice
{
    public function __construct(private readonly EnginePricing $pricing) {}

    public function quote(EnginePrice $price, EngineResult $result): string
    {
        try {
            return $this->amount($price, $result);
        } catch (Throwable $failure) {
            report($failure);

            return (string) BigDecimal::of((string) $price->price_per_call_usd)
                ->plus(BigDecimal::of((string) $price->price_per_1k_input_tokens_usd)->multipliedBy($result->inputTokens)->dividedBy(1000, 8, RoundingMode::HALF_UP))
                ->plus(BigDecimal::of((string) $price->price_per_1k_output_tokens_usd)->multipliedBy($result->outputTokens)->dividedBy(1000, 8, RoundingMode::HALF_UP))
                ->toScale(8, RoundingMode::HALF_UP);
        }
    }

    public function amount(EnginePrice $price, EngineResult $result): string
    {
        return $this->pricing->cost($price, $result);
    }
}
