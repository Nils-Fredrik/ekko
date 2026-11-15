<?php

// The formulas of docs/spec.md 9 on summed counters, without a database.

use App\Metrics\Formulas;

test('ratio divides and gives null for a zero denominator', function () {
    expect(Formulas::ratio(1, 4))->toBe(0.25)
        ->and(Formulas::ratio('3', '4'))->toBe(0.75)
        ->and(Formulas::ratio(0, 5))->toBe(0.0)
        ->and(Formulas::ratio(3, 0))->toBeNull()
        ->and(Formulas::ratio(3, null))->toBeNull();
});

test('share of voice is brand mentions over brand plus competitor mentions', function () {
    expect(Formulas::shareOfVoice(30, 70))->toBe(0.3)
        ->and(Formulas::shareOfVoice(5, 0))->toBe(1.0)
        ->and(Formulas::shareOfVoice(0, 12))->toBe(0.0)
        ->and(Formulas::shareOfVoice(0, 0))->toBeNull();
});

test('the summary applies every formula to the counters', function () {
    $summary = Formulas::summary([
        'responses' => 20, 'brand_mentioned' => 10, 'brand_recommended' => 4, 'sum_position' => 15, 'count_position' => 6,
        'list_responses' => 8, 'first_place' => 2, 'sum_sentiment' => '3.0', 'count_sentiment' => 10, 'own_cited' => 5,
        'competitor_mentions' => 30,
    ]);

    expect($summary->visibility->value)->toBe(0.5)->and($summary->visibility->n)->toBe(20)
        ->and($summary->recommendationRate->value)->toBe(0.2)
        ->and($summary->shareOfVoice->value)->toBe(0.25)
        ->and($summary->averagePosition->value)->toBe(2.5)->and($summary->averagePosition->n)->toBe(6)
        ->and($summary->firstPlaceRate->value)->toBe(0.25)->and($summary->firstPlaceRate->n)->toBe(8)
        ->and($summary->citationRate->value)->toBe(0.25)
        ->and($summary->sentiment->value)->toBe(0.3)->and($summary->sentiment->n)->toBe(10);
});

test('an empty set of counters gives null values and zero n', function () {
    $summary = Formulas::summary([]);

    expect($summary->responses)->toBe(0)
        ->and($summary->visibility->value)->toBeNull()
        ->and($summary->shareOfVoice->value)->toBeNull()
        ->and($summary->averagePosition->n)->toBe(0)
        ->and($summary->toArray()['sentiment'])->toBe(['value' => null, 'n' => 0, 'low_sample' => true]);
});
