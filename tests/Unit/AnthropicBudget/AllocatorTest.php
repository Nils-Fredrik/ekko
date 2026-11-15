<?php

use App\AnthropicBudget\Allocator;
use App\AnthropicBudget\Cycle;
use App\AnthropicBudget\Money;
use Carbon\CarbonImmutable;

test('billing cycles clamp month ends without drifting after February', function () {
    $cycle = Cycle::at(CarbonImmutable::parse('2028-02-29T12:00:00Z'), 31);
    expect($cycle->start->toDateString())->toBe('2028-02-29')->and($cycle->end->toDateString())->toBe('2028-03-31');
    $cycle = Cycle::at(CarbonImmutable::parse('2026-12-05'), 15);
    expect($cycle->start->toDateString())->toBe('2026-11-15')->and($cycle->end->toDateString())->toBe('2026-12-15');
});

test('twelve brands use ninety to one hundred percent over full billing cycles without overspending', function () {
    foreach ([28, 30, 31] as $days) {
        $pool = Money::units('500');
        $spent = 0;
        $offers = [];
        foreach (range(1, 12) as $brand) {
            foreach (range(1, 30) as $prompt) {
                $offers[] = ['key' => "$brand:$prompt", 'kind' => 'sonnet_daily', 'cost' => Money::units('0.025')];
            }
        }
        foreach (range(1, 12) as $brand) {
            foreach (range(1, 30) as $prompt) {
                $offers[] = ['key' => "$brand:repeat:$prompt", 'kind' => 'repetition', 'cost' => Money::units('0.02')];
            }
        }
        for ($day = 0; $day < $days; $day++) {
            $remaining = $days - $day;
            $base = Money::units('9'); // Twelve brands, 0.75 USD baseline per brand/day.
            $allocation = (new Allocator)->allocate($pool, $spent, $base * $remaining, $remaining, $offers);
            $actualBase = $allocation['reduced'] ? Money::units('3') : $base;
            expect($spent + $actualBase)->toBeLessThanOrEqual($pool);
            $spent += $actualBase;
            $spent += array_sum(array_column($allocation['additions'], 'cost'));
            expect($spent)->toBeLessThanOrEqual($pool);
            expect($allocation['buffer'] === 0)->toBe($remaining <= 3);
        }
        expect($spent / $pool)->toBeGreaterThanOrEqual(0.9)->toBeLessThanOrEqual(1.0);
    }
});

test('allocation respects tier order and reduced mode at the exact ninety-five percent threshold', function () {
    $allocator = new Allocator;
    $offers = [['key' => '1', 'kind' => 'sonnet_daily', 'cost' => 3], ['key' => '2', 'kind' => 'repetition', 'cost' => 3], ['key' => '3', 'kind' => 'insight', 'cost' => 1]];
    expect(array_column($allocator->allocate(100, 90, 5, 1, $offers)['additions'], 'kind'))->toBe(['sonnet_daily', 'insight'])
        ->and($allocator->allocate(100, 94, 0, 1, $offers)['reduced'])->toBeFalse()
        ->and($allocator->allocate(100, 95, 0, 1, $offers)['additions'])->toBe([])
        ->and($allocator->allocate(100, 0, 0, 30, $offers, true)['reduced'])->toBeTrue();
});
