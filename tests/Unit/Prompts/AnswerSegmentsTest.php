<?php

use App\Models\Mention;
use App\Prompts\AnswerSegments;

function highlight(string $answer, string $surface, int $offset = 0): array
{
    return collect(AnswerSegments::from($answer, [new Mention([
        'entity_type' => 'brand',
        'surface_name' => $surface,
        'first_char_offset' => $offset,
    ])]))->where('kind', 'brand')->pluck('text')->all();
}

it('highlights a repeated name with the same spelling and leaves another word and another case plain', function () {
    $answer = 'Apple sells phones; pineapple is fruit. apple again. Apple too.';

    expect(highlight($answer, 'Apple'))->toBe(['Apple', 'Apple'])
        ->and(collect(AnswerSegments::from($answer, [new Mention([
            'entity_type' => 'brand',
            'surface_name' => 'Apple',
            'first_char_offset' => 0,
        ])]))->where('kind', 'plain')->implode('text'))->toContain('pineapple')
        ->toContain('apple again');
});

it('highlights another case when the alias is case insensitive', function () {
    $answer = 'Apple sells phones. apple again.';
    $mention = new Mention([
        'entity_type' => 'brand',
        'entity_id' => 1,
        'surface_name' => 'Apple',
        'first_char_offset' => 0,
    ]);
    $fold = [AnswerSegments::caseFoldKey('brand', 1, 'Apple') => true];

    expect(collect(AnswerSegments::from($answer, [$mention], $fold))->where('kind', 'brand')->pluck('text')->all())
        ->toBe(['Apple', 'apple']);
});

it('keeps a possessive and a hyphen compound, and skips a longer word', function () {
    $answer = "Volvos motor. Volvo's bil. Volvo\u{2019}s hjul. Volvo-eier. Volvoer, Volvobil og Volvo240.";

    expect(highlight($answer, 'Volvo'))->toBe(['Volvo', 'Volvo', 'Volvo', 'Volvo']);
});
