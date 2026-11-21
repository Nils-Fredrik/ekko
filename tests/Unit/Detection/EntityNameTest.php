<?php

use App\Detection\EntityName;

// U21: the name key of unknown brands (docs/ux-arbeidsflate.md 6.3, ADR 0043).

test('EntityName::key normalizes a brand name', function (string $name, string $key) {
    expect(EntityName::key($name))->toBe($key);
})->with([
    'parenthesis' => ['Adobe Commerce (Magento)', 'adobe commerce'],
    'legal suffix' => ['Netthavn AS', 'netthavn'],
    'letters outside ascii' => ['Beste Byrå', 'beste byrå'],
    'upper case' => ['NETTHAVN', 'netthavn'],
    'suffix after punctuation' => ['  Netthavn,  AS. ', 'netthavn'],
    'gmbh' => ['Fjord Kaffe GmbH', 'fjord kaffe'],
    'inc with dot' => ['Acme Inc.', 'acme'],
    'ab' => ['Ølbryggeriet AB', 'ølbryggeriet'],
    'aps' => ['Kaffehuset ApS', 'kaffehuset'],
    'oy' => ['Kahvila Oy', 'kahvila'],
    'asa' => ['Havbruk ASA', 'havbruk'],
    'ltd and symbol' => ['Hansen & Co Ltd', 'hansen co'],
    'llc' => ['Northwind LLC', 'northwind'],
    'bv' => ['Tulip BV', 'tulip'],
    'hyphen' => ['Kaffe-Huset', 'kaffe huset'],
    'trademark sign' => ['Klarna®', 'klarna'],
    'full width (NFKC)' => ['Ｎｅｔｔｈａｖｎ', 'netthavn'],
    'suffix only at the end' => ['Asa Bakeri', 'asa bakeri'],
    'suffix inside a word' => ['Atlas', 'atlas'],
    'only one suffix' => ['Netthavn Holding AS', 'netthavn holding'],
    'the name is only a suffix' => ['AS', 'as'],
    'collapsed spaces' => ["Nordlys \t  Norge", 'nordlys norge'],
    'empty' => ['', ''],
    'only punctuation' => ['…', ''],
]);

test('EntityName::key is at most 200 characters', function () {
    expect(mb_strlen(EntityName::key(str_repeat('å', 300))))->toBe(200);
});
