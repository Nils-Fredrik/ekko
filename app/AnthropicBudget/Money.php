<?php

namespace App\AnthropicBudget;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Money
{
    public static function units(string $usd): int
    {
        $value = BigDecimal::of($usd)->toScale(8, RoundingMode::Ceiling);
        if ($value->isNegative()) {
            throw new InvalidArgumentException('Budget amounts cannot be negative.');
        }

        return $value->getUnscaledValue()->toInt();
    }

    public static function usd(int $units): string
    {
        return (string) BigDecimal::ofUnscaledValue($units, 8);
    }
}
