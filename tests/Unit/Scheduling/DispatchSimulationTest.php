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

test('multiweek prompts keep their local calendar day across new year and both clocks changing', function (string $frequency, int $interval, string $zone) {
    $start = CarbonImmutable::parse('2025-12-01 00:03', $zone);
    foreach ([1, 7, 123] as $prompt) {
        $firstDue = CarbonImmutable::parse('1970-01-04', $zone)->addDays(crc32((string) $prompt) % $interval);
        expect(PromptSchedule::due($prompt, $frequency, $firstDue))->toBeTrue();
        $due = [];
        $dueLate = [];
        for ($offset = 0; $offset < 400; $offset++) {
            $day = $start->addDays($offset);
            if (PromptSchedule::due($prompt, $frequency, $day)) {
                $due[] = $offset;
                expect($day->dayOfWeek)->toBe(crc32((string) $prompt) % 7);
            }
            if (PromptSchedule::due($prompt, $frequency, $day->endOfDay())) {
                $dueLate[] = $offset;
            }
        }
        expect($due[0])->toBeLessThan($interval)
            ->and($due)->toBe(range($due[0], 399, $interval))
            ->and($dueLate)->toBe($due);
    }
})->with([['every_14_days', 14], ['every_21_days', 21]])->with(['Europe/Oslo', 'America/Los_Angeles']);

test('every package cap includes exactly the frequencies at or below its rank', function () {
    $ordered = ['daily', 'weekly', 'every_14_days', 'every_21_days', 'monthly', 'off'];
    foreach ($ordered as $limit => $maximum) {
        expect(PromptSchedule::frequencies($maximum))->toBe(array_slice($ordered, $limit));
        foreach ($ordered as $rank => $frequency) {
            expect(PromptSchedule::allows($frequency, $maximum))->toBe($rank >= $limit)
                ->and(PromptSchedule::capped($frequency, $maximum))->toBe($rank >= $limit ? $frequency : $maximum);
        }
    }
    expect(PromptSchedule::allows('hourly', 'daily'))->toBeFalse();
});

test('existing prompts keep their original weekly weekday and monthly date', function () {
    $february = CarbonImmutable::parse('2028-02-01', 'Europe/Oslo');
    foreach ([1, 7, 123] as $id) {
        $hash = crc32((string) $id);
        for ($day = 1; $day <= $february->daysInMonth; $day++) {
            $date = $february->setDay($day);
            expect(PromptSchedule::due($id, 'weekly', $date))->toBe($date->dayOfWeek === $hash % 7)
                ->and(PromptSchedule::due($id, 'monthly', $date))->toBe($day === $hash % 28 + 1);
        }
    }
});
