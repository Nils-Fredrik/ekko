<?php

namespace App\ActionEngine;

/**
 * Maps the expected effect per month to the five levels of docs/spec.md 11.3 (1 = very low … 5 = very high),
 * with the thresholds in config('ekko.actions.impact_levels').
 */
final class ImpactScale
{
    public static function level(float $score): int
    {
        /** @var array<int, int|float> $thresholds */
        $thresholds = config('ekko.actions.impact_levels');
        $level = 1;
        foreach ($thresholds as $candidate => $from) {
            if ($score >= (float) $from) {
                $level = max($level, (int) $candidate);
            }
        }

        return min(5, max(1, $level));
    }
}
