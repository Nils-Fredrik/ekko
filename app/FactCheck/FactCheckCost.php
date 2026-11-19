<?php

namespace App\FactCheck;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Cost of one fact check call from config('ekko.anthropic.factcheck'), USD per million tokens. Like the wording
 * model, the fact check model is not an engine in `engines`, so the price lives in the config.
 */
final class FactCheckCost
{
    public function of(int $inputTokens, int $outputTokens): string
    {
        /** @var array<string, string> $config */
        $config = config('ekko.anthropic.factcheck');

        return (string) BigDecimal::of($config['input_usd_per_mtok'])->multipliedBy($inputTokens)
            ->plus(BigDecimal::of($config['output_usd_per_mtok'])->multipliedBy($outputTokens))
            ->dividedByExact(1_000_000)
            ->toScale(8, RoundingMode::HalfUp);
    }
}
