<?php

namespace App\Extraction;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Cost of one extraction call from config('ekko.anthropic.extraction') (docs/spec.md 12.2, ADR 0011): token
 * prices per million tokens, with the Message Batches price factor for calls made through a batch.
 */
final class ExtractionCost
{
    public function of(ExtractionOutcome $outcome): string
    {
        /** @var array<string, string> $config */
        $config = config('ekko.anthropic.extraction');

        $cost = BigDecimal::of($config['input_usd_per_mtok'])->multipliedBy($outcome->inputTokens)
            ->plus(BigDecimal::of($config['output_usd_per_mtok'])->multipliedBy($outcome->outputTokens))
            ->dividedByExact(1_000_000);

        if ($outcome->viaBatch) {
            $cost = $cost->multipliedBy($config['batch_price_factor']);
        }

        return (string) $cost->toScale(8, RoundingMode::HalfUp);
    }
}
