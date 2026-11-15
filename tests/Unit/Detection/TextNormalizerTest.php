<?php

use App\Detection\TextNormalizer;

it('lowercases for comparison but keeps the cased form', function () {
    $text = (new TextNormalizer)->normalize('Fjordkaffe ER Best');

    expect($text->cased)->toBe('Fjordkaffe ER Best')
        ->and($text->lower)->toBe('fjordkaffe er best');
});

it('keeps æ, ø and å and unifies ä with æ and ö with ø', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('Bærum Bjørn Åse')->lower)->toBe('bærum bjørn åse')
        ->and($normalizer->normalize('Smörgås Bjärred Björn')->lower)->toBe('smørgås bjærred bjørn');
});

it('treats a decomposed å like a composed å (NFKC) and keeps it as å', function () {
    $text = (new TextNormalizer)->normalize("A\u{030A}se");

    expect($text->lower)->toBe('åse');
});

it('treats aa as å for alias comparison', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('Aasen haakon')->lower)->toBe('åsen håkon')
        ->and($normalizer->normalize('AAsen')->cased)->toBe('Åsen');
});

it('drops other accents when folding but not without folding', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('Café Müller Piñata')->lower)->toBe('cafe muller pinata')
        ->and($normalizer->normalize('Café Björn', foldDiacritics: false)->lower)->toBe('café björn');
});

it('applies NFKC to compatibility characters', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize("\u{FB01}sk")->lower)->toBe('fisk')
        ->and($normalizer->normalize("\u{FF26}\u{FF4A}ord")->cased)->toBe('Fjord')
        ->and($normalizer->normalize("a\u{00A0}b")->cased)->toBe('a b');
});

it('removes invisible characters and collapses whitespace', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize("Fjord\u{200B}kaffe\u{00AD}!")->cased)->toBe('Fjordkaffe!')
        ->and($normalizer->normalize("a \n\t  b")->cased)->toBe('a b');
});

it('unifies typographic apostrophes', function () {
    expect((new TextNormalizer)->normalize("Ola\u{2019}s")->cased)->toBe("Ola's");
});

it('maps normalized characters back to code point offsets in the original', function () {
    $text = (new TextNormalizer)->normalize("Hei\u{200B}  Åse");
    // "Hei" 0-2, ZWSP 3, two spaces 4-5, Åse 6-8
    $byte = strpos($text->cased, 'Åse');

    expect($text->cased)->toBe('Hei Åse')
        ->and($text->originalRange($byte, $byte + strlen('Åse')))->toBe([6, 9])
        ->and($text->originalSlice(6, 9))->toBe('Åse');
});

it('maps a folded aa back to both original letters', function () {
    $text = (new TextNormalizer)->normalize('Se Aasen nå');
    $byte = strpos($text->cased, 'Åsen');

    expect($text->originalRange($byte, $byte + strlen('Åsen')))->toBe([3, 8]);
});

it('survives invalid UTF-8 and empty text', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize("ok \xC3\x28 ok")->isEmpty())->toBeFalse()
        ->and($normalizer->normalize('')->isEmpty())->toBeTrue();
});
