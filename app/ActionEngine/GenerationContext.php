<?php

namespace App\ActionEngine;

use App\Models\Brand;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The brand and the dates of one generation. today is the tenant-local day the generation runs; windows end the
 * day before, so only complete days count.
 */
final readonly class GenerationContext
{
    /**
     * @param  Collection<int, Market>  $markets  the brand's active markets
     */
    public function __construct(
        public Brand $brand,
        public Collection $markets,
        public CarbonImmutable $today,
    ) {}

    /**
     * The latest $days complete local days: [from, to] as Y-m-d.
     *
     * @return array{0: string, 1: string}
     */
    public function window(int $days): array
    {
        return [$this->today->subDays(max(1, $days))->toDateString(), $this->today->subDay()->toDateString()];
    }

    /** Answers in $days days scaled to a 30-day month. */
    public static function perMonth(int $answers, int $days): float
    {
        return $answers * 30 / max(1, $days);
    }
}
