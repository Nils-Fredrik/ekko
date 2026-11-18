<?php

namespace App\Reports;

/** One schedule whose latest completed period has no stored PDF yet. */
final class DueReport
{
    /**
     * @param  list<string>  $recipients
     * @param  array<string, int|float|string|null>  $summary
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly int $brandId,
        public readonly string $cadence,
        public readonly string $locale,
        public readonly array $recipients,
        public readonly string $from,
        public readonly string $to,
        public readonly array $summary,
    ) {}
}
