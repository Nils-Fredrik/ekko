<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** The last completed week or month in the tenant's timezone (docs/spec.md 11.2). */
final class ReportWindow
{
    /**
     * @return array{0: string, 1: string} start and end, both included, as Y-m-d
     */
    public static function previous(string $cadence, DateTimeZone $timezone, CarbonImmutable $now): array
    {
        $local = $now->setTimezone($timezone)->startOfDay();
        if ($cadence === 'monthly') {
            $start = $local->startOfMonth()->subMonth();

            return [$start->toDateString(), $start->endOfMonth()->toDateString()];
        }
        if ($cadence !== 'weekly') {
            throw new InvalidArgumentException("Unknown report cadence: {$cadence}");
        }
        $start = $local->startOfWeek(CarbonImmutable::MONDAY)->subWeek();

        return [$start->toDateString(), $start->addDays(6)->toDateString()];
    }

    /**
     * The period a report is compared with. A month is the previous calendar month,
     * not an equal number of days.
     *
     * @return array{0: string, 1: string}
     */
    public static function compared(string $cadence, string $from, string $to): array
    {
        if ($cadence === 'monthly') {
            $start = CarbonImmutable::parse($from)->startOfMonth()->subMonth();

            return [$start->toDateString(), $start->endOfMonth()->toDateString()];
        }
        if ($cadence !== 'weekly') {
            throw new InvalidArgumentException("Unknown report cadence: {$cadence}");
        }
        $end = CarbonImmutable::parse($from)->subDay();
        $length = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));

        return [$end->subDays($length)->toDateString(), $end->toDateString()];
    }

    /**
     * Completed periods a later run may still finish, newest first.
     * Four weeks and two months cover the next scheduled trigger after a failed one.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function recent(string $cadence, DateTimeZone $timezone, CarbonImmutable $now): array
    {
        $limit = $cadence === 'monthly' ? 2 : 4;
        $windows = [];
        $cursor = $now;
        for ($i = 0; $i < $limit; $i++) {
            $window = self::previous($cadence, $timezone, $cursor);
            $windows[] = $window;
            $cursor = CarbonImmutable::parse($window[0], $timezone)->startOfDay();
        }

        return $windows;
    }
}
