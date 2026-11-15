<?php

use App\Detection\AliasPattern;
use App\Detection\EntityMatcher;
use App\Detection\ListPositions;

/** @return array<string, int|null> list position per alias name */
function positionsFor(string $text, array $names): array
{
    $aliases = [];
    foreach ($names as $i => $name) {
        $aliases[] = new AliasPattern('brand', $i, $name, 'case_insensitive');
    }

    $result = [];
    foreach ((new EntityMatcher)->detect($text, $aliases) as $mention) {
        $result[$names[$mention->entityId]] = $mention->listPosition;
    }

    return $result;
}

it('numbers items of numbered and bulleted lists from 1', function (string $text) {
    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma']))->toBe(['Alfa' => 1, 'Beta' => 2, 'Gamma' => 3]);
})->with([
    'dot' => ["1. Alfa\n2. Beta\n3. Gamma"],
    'paren' => ["1) Alfa\n2) Beta\n3) Gamma"],
    'dash' => ["- Alfa\n- Beta\n- Gamma"],
    'star' => ["* Alfa\n* Beta\n* Gamma"],
    'bullet' => ["• Alfa\n• Beta\n• Gamma"],
    'en dash' => ["– Alfa\n– Beta\n– Gamma"],
    'crlf' => ["1. Alfa\r\n2. Beta\r\n3. Gamma"],
    'indented list' => ["  1. Alfa\n  2. Beta\n  3. Gamma"],
    'bold names' => ["1. **Alfa** – x\n2. **Beta** – y\n3. **Gamma** – z"],
    'after intro' => ["Her er tre:\n\n- Alfa\n- Beta\n- Gamma\n"],
]);

it('gives null outside lists', function () {
    expect(positionsFor('Alfa er best, men Beta er billigere.', ['Alfa', 'Beta']))->toBe(['Alfa' => null, 'Beta' => null]);
});

it('does not read a marker without a following space or a long number as a list', function () {
    expect(positionsFor("1.5 liter Alfa\n-Beta\n2024. Gamma", ['Alfa', 'Beta', 'Gamma']))
        ->toBe(['Alfa' => null, 'Beta' => null, 'Gamma' => null]);
});

it('treats continuation lines and nested items as part of the item above', function () {
    $text = "1. Alfa\n   fortsetter her med Beta\n   - underpunkt Gamma\n2. Delta";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma', 'Delta']))->toBe(['Alfa' => 1, 'Beta' => 1, 'Gamma' => 1, 'Delta' => 2]);
});

it('keeps a loose list together across blank lines but ends it at a plain paragraph', function () {
    $text = "1. Alfa\n\n2. Beta\n\nOppsummert er Gamma best.\n\n1. Delta";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma', 'Delta']))->toBe(['Alfa' => 1, 'Beta' => 2, 'Gamma' => null, 'Delta' => 1]);
});

it('restarts the count in a new list', function () {
    $text = "- Alfa\n- Beta\n\nSå:\n\n1. Gamma\n2. Delta";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma', 'Delta']))->toBe(['Alfa' => 1, 'Beta' => 2, 'Gamma' => 1, 'Delta' => 2]);
});

it('uses the first occurrence inside a list when the name also appears in running text', function () {
    $text = "Alfa nevnes først. Liste:\n1. Beta\n2. Alfa";

    expect(positionsFor($text, ['Alfa', 'Beta']))->toBe(['Alfa' => 2, 'Beta' => 1]);
});

it('returns item ranges in code points', function () {
    $items = (new ListPositions)->items("Å\n1. Ø\n2. Æ");

    expect($items)->toBe([
        ['start' => 2, 'end' => 6, 'position' => 1],
        ['start' => 7, 'end' => 11, 'position' => 2],
    ]);
});

it('continues a numbered list across paragraphs between the items', function () {
    $text = "1. **Alfa**\n\nAlfa er trygg, og Epsilon er lik.\n\n2. **Beta**\n\nBeta er rask.\n\n3. **Gamma**\n\nSlutt.";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma', 'Epsilon']))->toBe(['Alfa' => 1, 'Epsilon' => 1, 'Beta' => 2, 'Gamma' => 3]);
});

it('restarts instead of continuing when the number does not follow on', function () {
    $text = "1. Alfa\n\nTekst.\n\n1. Beta\n\nTekst.\n\n5. Gamma";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma']))->toBe(['Alfa' => 1, 'Beta' => 1, 'Gamma' => 1]);
});

it('reads numbered markdown headings as list items', function () {
    $text = "### 1. Alfa\n\nGod.\n\n### 2. Beta\n\nBra.\n\n## Oppsummering\n\nGamma vinner.";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma']))->toBe(['Alfa' => 1, 'Beta' => 2, 'Gamma' => null]);
});

it('does not continue a numbered list after a bullet list', function () {
    $text = "1. Alfa\n\nTekst.\n\n- Beta\n\n2. Gamma";

    expect(positionsFor($text, ['Alfa', 'Beta', 'Gamma']))->toBe(['Alfa' => 1, 'Beta' => 1, 'Gamma' => 1]);
});
