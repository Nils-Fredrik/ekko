<?php

namespace App\AnthropicBudget;

use InvalidArgumentException;

/** Integer USD fractions: allocation cannot round past the available daily envelope. */
final class Allocator
{
    /**
     * Offers must be ordered by tier (daily Sonnet, variable answers, monthly insights), then fairness rank.
     *
     * @template TOffer of array{key: string, kind: string, cost: int}
     *
     * @param  list<TOffer>  $offers
     * @return array{spent: int, forecast: int, buffer: int, available: int, reduced: bool, additions: list<TOffer>}
     */
    public function allocate(int $pool, int $spent, int $baselineRemaining, int $daysLeft, array $offers, bool $exhausted = false): array
    {
        if (min($pool, $spent, $baselineRemaining) < 0 || $daysLeft < 1) {
            throw new InvalidArgumentException('Invalid budget forecast.');
        }
        $buffer = $daysLeft > 3 ? intdiv($baselineRemaining + 9, 10) : 0;
        $forecast = $spent + $baselineRemaining + $buffer;
        $available = max(0, $pool - $forecast);
        $reduced = $exhausted || $spent >= intdiv($pool * 95, 100);
        $daily = intdiv($available, $daysLeft);
        $additions = [];
        if (! $reduced) {
            foreach ($offers as $offer) {
                if ($offer['cost'] <= 0) {
                    throw new InvalidArgumentException('An addition must have a positive estimate.');
                }
                if ($offer['cost'] <= $daily) {
                    $additions[] = $offer;
                    $daily -= $offer['cost'];
                }
            }
        }

        return compact('spent', 'forecast', 'buffer', 'available', 'reduced', 'additions');
    }
}
