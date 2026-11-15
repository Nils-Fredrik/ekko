<?php

use App\Scheduling\FairQueue;
use App\Scheduling\PromptSchedule;
use Carbon\CarbonImmutable;

test('500 prompts across three tenants are spread over seven days and interleaved', function () {
    $tenants = [1 => range(1, 300), 2 => range(301, 450), 3 => range(451, 500)];
    $counts = [];
    $seen = [];
    for ($offset = 0; $offset < 7; $offset++) {
        $day = CarbonImmutable::parse('2026-11-15', 'Europe/Oslo')->addDays($offset);
        $due = array_map(fn ($ids) => array_values(array_filter($ids, fn ($id) => PromptSchedule::due($id, 'weekly', $day))), $tenants);
        $queue = iterator_to_array(FairQueue::interleave($due));
        $counts[] = count($queue);
        foreach ($queue as $entry) {
            expect($entry['item'])->toBeIn($tenants[$entry['tenant_id']]);
            $seen[] = $entry['item'];
        }
        $remaining = array_map('count', $due);
        $position = 0;
        while (array_sum($remaining) > 0) {
            foreach ($remaining as $tenant => $count) {
                if ($count > 0) {
                    expect($queue[$position++]['tenant_id'])->toBe($tenant);
                    $remaining[$tenant]--;
                }
            }
        }
    }
    sort($seen);
    expect($seen)->toBe(range(1, 500));
    // Every bucket must remain within 25% of the mean for this fixed acceptance dataset.
    foreach ($counts as $count) {
        expect($count)->toBeGreaterThanOrEqual(54)->toBeLessThanOrEqual(90);
    }
});

test('monthly cadence occurs once even in February and never on day 29 through 31', function () {
    for ($id = 1; $id <= 100; $id++) {
        $dates = [];
        for ($day = 1; $day <= 31; $day++) {
            if (PromptSchedule::due($id, 'monthly', CarbonImmutable::create(2026, 1, $day))) {
                $dates[] = $day;
            }
        }
        expect($dates)->toHaveCount(1)->and($dates[0])->toBeLessThanOrEqual(28);
    }
});

test('queue preserves core-first input order and handles empty and lazy tenants', function () {
    $lazy = (function () {
        yield 'core';
        yield 'standard';
    })();
    expect(iterator_to_array(FairQueue::interleave([1 => [], 2 => $lazy, 3 => ['other']])))
        ->toBe([['tenant_id' => 2, 'item' => 'core'], ['tenant_id' => 3, 'item' => 'other'], ['tenant_id' => 2, 'item' => 'standard']]);
    expect(iterator_to_array(FairQueue::interleave([])))->toBe([]);
    expect(PromptSchedule::due(1, 'off', now()))->toBeFalse();
    expect(fn () => PromptSchedule::due(1, 'invalid', now()))->toThrow(InvalidArgumentException::class);
});
