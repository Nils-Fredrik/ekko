<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Exact usage totals; BYOK is shown for reconciliation and never invoiced by Ekko. */
final class BillingTotals
{
    /**
     * @param  iterable<array{engine_key: string|null, calls: int, cost_usd: string, billed_to: string}>  $events
     * @return array{calls: int, platform_cost_usd: string, byok_cost_usd: string, engine_costs_usd: array<string, string>, markup_percent: string|null, markup_usd: string|null, invoice_total_usd: string|null}
     */
    public static function summarize(iterable $events, ?string $markupPercent): array
    {
        $markup = $markupPercent === null ? null : BigDecimal::of($markupPercent);
        if ($markup?->isNegative()) {
            throw new InvalidArgumentException('Markup cannot be negative.');
        }
        $platform = BigDecimal::zero();
        $byok = BigDecimal::zero();
        $engines = [];
        $calls = 0;
        foreach ($events as $event) {
            $cost = BigDecimal::of($event['cost_usd']);
            if ($cost->isNegative() || $event['calls'] < 0) {
                throw new InvalidArgumentException('Usage cannot be negative.');
            }
            $calls += $event['calls'];
            if ($event['billed_to'] === 'byok') {
                $byok = $byok->plus($cost);
            } elseif ($event['billed_to'] === 'platform') {
                $platform = $platform->plus($cost);
                $key = $event['engine_key'] ?? 'other';
                $engines[$key] = ($engines[$key] ?? BigDecimal::zero())->plus($cost);
            } else {
                throw new InvalidArgumentException('Unknown billing account.');
            }
        }
        ksort($engines);
        $markupCost = $markup === null ? null : $platform->multipliedBy($markup)->multipliedBy('0.01');

        return [
            'calls' => $calls,
            'platform_cost_usd' => (string) $platform->toScale(8),
            'byok_cost_usd' => (string) $byok->toScale(8),
            'engine_costs_usd' => array_map(fn (BigDecimal $amount) => (string) $amount->toScale(8), $engines),
            // Unknown A7 settings must not silently become a zero-percent invoice.
            'markup_percent' => $markup === null ? null : (string) $markup,
            'markup_usd' => $markupCost === null ? null : (string) $markupCost->toScale(8, RoundingMode::HALF_UP),
            'invoice_total_usd' => $markupCost === null ? null : (string) $platform->plus($markupCost)->toScale(2, RoundingMode::HALF_UP),
        ];
    }
}
