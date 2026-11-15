<?php

namespace App\AnthropicBudget;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class Cycle
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public static function at(CarbonImmutable $now, int $startDay): self
    {
        if ($startDay < 1 || $startDay > 31) {
            throw new InvalidArgumentException('Billing day must be between 1 and 31.');
        }
        $today = $now->utc()->startOfDay();
        $month = $today->startOfMonth();
        $anchor = fn (CarbonImmutable $month): CarbonImmutable => $month->day(min($startDay, $month->daysInMonth));
        $start = $anchor($month);
        if ($today->lessThan($start)) {
            $month = $month->subMonth();
            $start = $anchor($month);
        }

        return new self($start, $anchor($month->addMonth()));
    }

    public function daysLeft(CarbonImmutable $now): int
    {
        return max(0, (int) $now->utc()->startOfDay()->diffInDays($this->end));
    }
}
