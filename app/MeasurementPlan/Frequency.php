<?php

namespace App\MeasurementPlan;

use App\Scheduling\PromptSchedule;
use InvalidArgumentException;

/**
 * The shared frequency contract for brand plans, market choices and package caps (docs/spec.md 6.2), on top of
 * the calendar rules in PromptSchedule (T52). Values are ordered from rarest to most frequent.
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
            $rarest = PromptSchedule::capped($rarest, $frequency);
        }

        return $rarest;
    }

    public static function allows(string $frequency, string $cap): bool
    {
        return PromptSchedule::allows($frequency, $cap);
    }

    /**
     * Every value the calendar rules can plan, rarest first.
     *
     * @return list<string>
     */
    public static function schedulable(): array
    {
        return array_reverse(PromptSchedule::frequencies());
    }
}
