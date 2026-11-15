<?php

use App\Detection\AliasPattern;
use App\Detection\EntityMatcher;

function aliasList(array ...$rows): array
{
    return array_map(fn (array $row): AliasPattern => new AliasPattern($row[0], $row[1], $row[2], $row[3] ?? 'case_insensitive'), $rows);
}

it('matches whole words only', function (string $text, bool $expected) {
    $mentions = (new EntityMatcher)->detect($text, aliasList(['brand', 1, 'Volvo']));

    expect($mentions !== [])->toBe($expected);
})->with([
    'plain' => ['Jeg liker Volvo.', true],
    'start and end of text' => ['Volvo', true],
    'in parentheses' => ['bilen (Volvo) er fin', true],
    'hyphen compound' => ['Volvo-eier', true],
    'compound word after' => ['Volvobil er kjekt', false],
    'compound word before' => ['Minivolvo er kjekt', false],
    'longer word' => ['Volvoer er ikke ord', false],
    'digit after' => ['Volvo240 er gammel', false],
    'digit before' => ['240Volvo er rart', false],
    'possessive s' => ['Volvos motorer', true],
    'possessive apostrophe s' => ["Volvo's motorer", true],
    'typographic possessive' => ["Volvo\u{2019}s motorer", true],
    'plural is not possessive' => ['Volvoene er biler', false],
    'underscore compound' => ['Volvo_xc90 er bil', true],
]);

it('is case-sensitive for exact_word and not for case_insensitive', function () {
    $matcher = new EntityMatcher;
    $exact = aliasList(['brand', 1, 'Fast', 'exact_word']);
    $loose = aliasList(['brand', 1, 'Fast', 'case_insensitive']);

    expect($matcher->detect('Det gikk fast.', $exact))->toBe([])
        ->and($matcher->detect('Fast er best.', $exact))->toHaveCount(1)
        ->and($matcher->detect('Det gikk fast.', $loose))->toHaveCount(1);
});

it('unifies Swedish and Norwegian letters and aa/å in aliases and text', function (string $alias, string $text) {
    expect((new EntityMatcher)->detect($text, aliasList(['brand', 1, $alias])))->toHaveCount(1);
})->with([
    'ö vs ø' => ['Bjørn', 'Där sitter Björn.'],
    'ø vs ö' => ['Björn', 'Der sitter Bjørn.'],
    'ä vs æ' => ['Bjærred', 'Bjärred ligger i Skåne.'],
    'aa vs å' => ['Åsen', 'Aasen er et sted.'],
    'å vs aa' => ['Aasen', 'Åsen er et sted.'],
    'accents' => ['Café Nord', 'Vi møttes på cafe nord.'],
    'uppercase Ö' => ['Björn', 'BJÖRN SKRIKER'],
    'decomposed' => ['Åse', "A\u{030A}se kom."],
]);

it('does not mistake different letters for each other', function () {
    $matcher = new EntityMatcher;

    expect($matcher->detect('Bjorn er her.', aliasList(['brand', 1, 'Bjørn'])))->toBe([])
        ->and($matcher->detect('Ase er her.', aliasList(['brand', 1, 'Åse'])))->toBe([]);
});

it('records the first occurrence and the surface form as written', function () {
    $mentions = (new EntityMatcher)->detect('Se her. Så kommer ÅSEN-kaffe og åsen igjen.', aliasList(['brand', 7, 'Åsen']));

    expect($mentions)->toHaveCount(1)
        ->and($mentions[0]->entityType)->toBe('brand')
        ->and($mentions[0]->entityId)->toBe(7)
        ->and($mentions[0]->surfaceName)->toBe('ÅSEN')
        ->and($mentions[0]->firstCharOffset)->toBe(18);
});

it('counts offsets in code points, not bytes', function () {
    $mentions = (new EntityMatcher)->detect('Ærlig talt, Øl & Åse: Volvo', aliasList(['brand', 1, 'Volvo']));

    expect($mentions[0]->firstCharOffset)->toBe(22);
});

it('keeps the offset into the original text after invisible characters and whitespace runs', function () {
    $text = "Intro\u{200B}\n\n  Volvo";
    $mentions = (new EntityMatcher)->detect($text, aliasList(['brand', 1, 'Volvo']));

    expect(mb_substr($text, $mentions[0]->firstCharOffset, 5))->toBe('Volvo');
});

it('lets the longest overlapping match win and gives each entity one mention', function () {
    $aliases = aliasList(
        ['brand', 1, 'Telenor'],
        ['competitor', 2, 'Telenor Norge'],
        ['competitor', 2, 'Telenor Sverige'],
    );

    $mentions = (new EntityMatcher)->detect('Telenor Norge og Telenor.', $aliases);

    expect($mentions)->toHaveCount(2)
        ->and([$mentions[0]->entityType, $mentions[0]->surfaceName, $mentions[0]->firstCharOffset])->toBe(['competitor', 'Telenor Norge', 0])
        ->and([$mentions[1]->entityType, $mentions[1]->surfaceName, $mentions[1]->firstCharOffset])->toBe(['brand', 'Telenor', 17]);
});

it('keeps brands and competitors with the same id apart', function () {
    $mentions = (new EntityMatcher)->detect('A og B', aliasList(['brand', 1, 'A'], ['competitor', 1, 'B']));

    expect(collect($mentions)->map(fn ($m) => $m->entityType.':'.$m->entityId)->all())->toBe(['brand:1', 'competitor:1']);
});

it('handles multi-word aliases across whitespace', function () {
    $mentions = (new EntityMatcher)->detect("Nordlys\n  Rosteri er bra", aliasList(['brand', 1, 'nordlys rosteri']));

    expect($mentions)->toHaveCount(1)->and($mentions[0]->surfaceName)->toBe("Nordlys\n  Rosteri");
});

it('treats alias text literally, never as a pattern', function () {
    $matcher = new EntityMatcher;

    expect($matcher->detect('C++ og C er språk', aliasList(['brand', 1, 'C++'])))->toHaveCount(1)
        ->and($matcher->detect('Alt er bra', aliasList(['brand', 1, '.*'])))->toBe([])
        ->and($matcher->detect('a|b', aliasList(['brand', 1, 'a|b'])))->toHaveCount(1)
        ->and($matcher->detect('x', aliasList(['brand', 1, 'a|x'])))->toBe([]);
});

it('ignores empty aliases and empty answers', function () {
    $matcher = new EntityMatcher;

    expect($matcher->detect('Volvo', aliasList(['brand', 1, ''], ['brand', 1, '   '])))->toBe([])
        ->and($matcher->detect('', aliasList(['brand', 1, 'Volvo'])))->toBe([])
        ->and($matcher->detect('Volvo', []))->toBe([]);
});

it('matches regex aliases as written, case-insensitively', function () {
    $matcher = new EntityMatcher;
    $aliases = aliasList(['brand', 1, 'fjord\s?kaffe(?:ristere)?\b', 'regex']);

    expect($matcher->detect('Jeg liker Fjord Kaffe.', $aliases))->toHaveCount(1)
        ->and($matcher->detect('Jeg liker FJORDKAFFERISTERE.', $aliases))->toHaveCount(1)
        ->and($matcher->detect('Jeg liker fjordkaffet.', $aliases))->toBe([]);
});

it('uses unicode word boundaries in regex aliases', function () {
    $aliases = aliasList(['brand', 1, '\bøl\b', 'regex']);
    $matcher = new EntityMatcher;

    expect($matcher->detect('Vi drikker øl her.', $aliases))->toHaveCount(1)
        ->and($matcher->detect('Vi drikker køl her.', $aliases))->toBe([]);
});

it('does not fold diacritics for regex aliases', function () {
    $matcher = new EntityMatcher;

    expect($matcher->detect('Björn', aliasList(['brand', 1, 'bj[öo]rn', 'regex'])))->toHaveCount(1)
        ->and($matcher->detect('Björn', aliasList(['brand', 1, 'bjørn', 'regex'])))->toBe([]);
});

it('skips a regex alias that is invalid, empty-matching or catastrophic instead of failing', function () {
    $matcher = new EntityMatcher;
    $aliases = aliasList(
        ['brand', 1, '(unclosed', 'regex'],
        ['brand', 2, 'x*', 'regex'],
        ['brand', 3, '(a+)+$', 'regex'],
        ['brand', 4, '(?C1)a', 'regex'],
        ['brand', 5, 'ok', 'case_insensitive'],
    );

    $started = microtime(true);
    $mentions = $matcher->detect(str_repeat('a', 5000).'! ok', $aliases);

    expect($mentions)->toHaveCount(1)
        ->and($mentions[0]->entityId)->toBe(5)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('survives a long answer with many aliases quickly', function () {
    $aliases = [];
    for ($i = 0; $i < 300; $i++) {
        $aliases[] = new AliasPattern('competitor', $i, "Merke Nummer {$i}", 'case_insensitive');
    }
    $text = str_repeat('Dette er en lang tekst om kaffe og biler. ', 400).'Merke Nummer 299 vant.';

    $started = microtime(true);
    $mentions = (new EntityMatcher)->detect($text, $aliases);

    expect($mentions)->toHaveCount(1)
        ->and($mentions[0]->entityId)->toBe(299)
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('limits matches per alias without hiding the aliases after it', function () {
    $aliases = aliasList(['brand', 1, 'støy'], ['brand', 2, 'Ekko']);
    $text = str_repeat('støy ', 1000).'Ekko';

    $mentions = (new EntityMatcher)->detect($text, $aliases);

    expect(collect($mentions)->pluck('entityId')->all())->toBe([1, 2])
        ->and($mentions[1]->firstCharOffset)->toBe(5000);
});
