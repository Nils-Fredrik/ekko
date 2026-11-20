<?php

namespace App\MeasurementPlan;

use App\Scheduling\PromptSchedule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The shared frequency contract for brand plans, market choices and package caps (docs/spec.md 6.2).
 * Values are ordered from rarest to most frequent.
 */
final class Frequency
{
    /** @var list<string> */
    public const ORDER = ['off', 'monthly', 'every_21_days', 'every_14_days', 'weekly', 'daily'];

    public static function rank(string $frequency): int
    {
        $rank = array_search($frequency, self::ORDER, true);
        if ($rank === false) {
            throw new InvalidArgumentException('Unsupported frequency.');
        }

        return $rank;
    }

    /** The rarer of the given frequencies. */
    public static function rarest(string $first, string ...$others): string
    {
        $rarest = $first;
        foreach ($others as $frequency) {
            if (self::rank($frequency) < self::rank($rarest)) {
                $rarest = $frequency;
            }
        }

        return $rarest;
    }

    public static function allows(string $frequency, string $cap): bool
    {
        return self::rank($frequency) <= self::rank($cap);
    }

    /**
     * The values the calendar rules (PromptSchedule, T52) can plan today, in contract order. A plan is
     * never stored with an interval the scheduler cannot run, so the set grows with PromptSchedule alone.
     *
     * @return list<string>
     */
    public static function schedulable(): array
    {
        $day = CarbonImmutable::create(2026, 1, 1);

        return array_values(array_filter(self::ORDER, function (string $frequency) use ($day): bool {
            try {
                PromptSchedule::due(1, $frequency, $day);

                return true;
            } catch (InvalidArgumentException) {
                return false;
            }
        }));
    }
}
