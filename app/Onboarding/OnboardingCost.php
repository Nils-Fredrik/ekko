<?php

namespace App\Onboarding;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Cost of one wizard model call from config('ekko.anthropic.onboarding'): USD per million tokens. The wizard
 * model is not an engine in `engines`, so, like extraction (ADR 0011), the price lives in the config.
 */
final class OnboardingCost
{
    public function of(int $inputTokens, int $outputTokens): string
    {
        /** @var array<string, string> $config */
        $config = config('ekko.anthropic.onboarding');

        return (string) BigDecimal::of($config['input_usd_per_mtok'])->multipliedBy($inputTokens)
            ->plus(BigDecimal::of($config['output_usd_per_mtok'])->multipliedBy($outputTokens))
            ->dividedByExact(1_000_000)
            ->toScale(8, RoundingMode::HalfUp);
    }
}
