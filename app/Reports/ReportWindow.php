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
}
