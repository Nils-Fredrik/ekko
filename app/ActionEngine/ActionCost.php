<?php

namespace App\ActionEngine;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Cost of one wording call from config('ekko.anthropic.<settings>') (actions, or insight for ADR 0038), USD per million tokens. Like the wizard and
 * extraction, the wording model is not an engine in `engines`, so the price lives in the config.
 */
final class ActionCost
{
    public function of(int $inputTokens, int $outputTokens, string $settings = 'actions'): string
    {
        /** @var array<string, string> $config */
        $config = config("ekko.anthropic.{$settings}");

        return (string) BigDecimal::of($config['input_usd_per_mtok'])->multipliedBy($inputTokens)
            ->plus(BigDecimal::of($config['output_usd_per_mtok'])->multipliedBy($outputTokens))
            ->dividedByExact(1_000_000)
            ->toScale(8, RoundingMode::HalfUp);
    }
}
