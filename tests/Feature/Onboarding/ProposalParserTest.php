<?php

// What the wizard does with the model's JSON: counts and lengths are enforced here, because structured output
// cannot (docs/spec.md 13 and 16). Everything from the model is untrusted.

use App\Onboarding\Model\ProposalParser;
use Tests\Support\Onboarding\OnboardingFakes;

function parseBrand(string $json, string $name = 'Eksempel', string $domain = 'eksempel.no'): ?array
{
    return (new ProposalParser)->parseBrand($json, $name, $domain);
}

/** @return array<string, mixed> */
function brandData(array $overrides = []): array
{
    return $overrides + json_decode(OnboardingFakes::brandJson(), true);
}

function manyPrompts(int $count, int $core = 0, string $key = 'running_shoes'): array
{
    return array_map(fn ($i) => [
        'text' => "Spørsmål nummer {$i} om sko til løping",
        'cross_market_key' => $key,
        'intent' => 'category',
        'funnel_stage' => 'awareness',
        'is_core' => $i < $core,
    ], range(0, $count - 1));
}

it('returns the proposal as structured data with the spec fields', function () {
    $result = parseBrand(OnboardingFakes::brandJson());

    expect($result['warnings'])->toBe([])
        ->and($result['proposal'])->toHaveKeys(['industry', 'category', 'name_variants', 'competitors', 'topics', 'prompts'])
        ->and($result['proposal']['industry'])->toBe('Retail')
        ->and($result['proposal']['competitors'][1])->toBe(['name' => 'Skohuset', 'domain' => 'skohuset.example.no'])
        ->and($result['proposal']['topics'])->toBe([['name' => 'Løpesko', 'cross_market_key' => 'running_shoes'], ['name' => 'Skobutikker', 'cross_market_key' => 'shoe_stores']])
        ->and($result['proposal']['prompts'][0])->toBe([
            'text' => 'Hvilken skobutikk er best for løping nummer 1?',
            'cross_market_key' => 'shoe_stores',
            'intent' => 'category',
            'funnel_stage' => 'consideration',
            'priority' => 'core',
        ]);
});

it('rejects a reply that is not usable at all', function (string $json) {
    expect(parseBrand($json))->toBeNull();
})->with([
    'not json' => 'Her er forslagene dine:',
    'empty' => '',
    'a list' => '[1,2]',
    'a string' => '"x"',
    'missing prompts' => '{"industry":"a","category":"b","name_variants":[],"competitors":[],"topics":[]}',
    'wrong type' => '{"industry":1,"category":"b","name_variants":[],"competitors":[],"topics":[],"prompts":[]}',
    'not an array' => '{"industry":"a","category":"b","name_variants":"x","competitors":[],"topics":[],"prompts":[]}',
]);

it('keeps at most 10 competitors, 8 topics, 50 prompts, 10 core prompts and 10 name variants', function () {
    $data = brandData([
        'name_variants' => array_map(fn ($i) => "Variant {$i}", range(1, 30)),
        'competitors' => array_map(fn ($i) => ['name' => "Konkurrent {$i}", 'domain' => "konkurrent{$i}.example.no"], range(1, 30)),
        'topics' => array_map(fn ($i) => ['name' => "Tema {$i}", 'cross_market_key' => "tema_{$i}"], range(1, 20)),
        'prompts' => array_map(fn ($i) => [
            'text' => "Hva er det beste valget i kategori {$i}?",
            'cross_market_key' => 'tema_'.(($i % 8) + 1),
            'intent' => 'category',
            'funnel_stage' => 'awareness',
            'is_core' => true,
        ], range(1, 120)),
    ]);

    $proposal = parseBrand(json_encode($data))['proposal'];

    expect($proposal['name_variants'])->toHaveCount(10)
        ->and($proposal['competitors'])->toHaveCount(10)
        ->and($proposal['topics'])->toHaveCount(8)
        ->and($proposal['prompts'])->toHaveCount(50)
        ->and(count(array_filter($proposal['prompts'], fn ($p) => $p['priority'] === 'core')))->toBe(10);
});

it('drops competitors without a usable domain, and the brand itself', function () {
    $data = brandData(['competitors' => [
        ['name' => 'Egen', 'domain' => 'www.eksempel.no'],
        ['name' => 'Intern', 'domain' => '10.0.0.1'],
        ['name' => 'Lokal', 'domain' => 'localhost'],
        ['name' => 'Uten domene', 'domain' => ''],
        ['name' => '', 'domain' => 'navnlos.example.no'],
        ['name' => 'Dobbel', 'domain' => 'https://www.ekte.example.no/'],
        ['name' => 'Dobbel igjen', 'domain' => 'ekte.example.no'],
    ]]);

    $result = parseBrand(json_encode($data));

    expect($result['proposal']['competitors'])->toBe([['name' => 'Dobbel', 'domain' => 'ekte.example.no']])
        ->and($result['warnings'])->toContain('competitor_dropped');
});

it('drops name variants that repeat the name, and duplicates', function () {
    $data = brandData(['name_variants' => ['eksempel', 'Eksempel Sko', 'eksempel sko', '  ', 'Ex']]);

    expect(parseBrand(json_encode($data))['proposal']['name_variants'])->toBe(['Eksempel Sko', 'Ex']);
});

it('drops prompts with an email address or a phone number', function (string $text) {
    $data = brandData(['prompts' => [...manyPrompts(2), ['text' => $text, 'cross_market_key' => 'running_shoes', 'intent' => 'brand', 'funnel_stage' => 'decision', 'is_core' => false]]]);

    $result = parseBrand(json_encode($data));

    expect($result['proposal']['prompts'])->toHaveCount(2)->and($result['warnings'])->toBe(['prompt_personal_data']);
})->with([
    'email' => 'Kan jeg sende en e-post til ola.nordmann@eksempel.no om sko?',
    'norwegian number' => 'Ring 912 34 567 for å bestille sko',
    'international number' => 'Er +47 912 34 567 nummeret til butikken?',
    'dashed number' => 'Nummer 912-34-567 i Oslo, er det riktig butikk?',
]);

it('keeps prompts that only contain ordinary numbers', function () {
    $data = brandData(['prompts' => [['text' => 'Hva er de beste løpeskoene under 2000 kroner i 2026?', 'cross_market_key' => 'running_shoes', 'intent' => 'category', 'funnel_stage' => 'decision', 'is_core' => false]]]);

    expect(parseBrand(json_encode($data))['proposal']['prompts'])->toHaveCount(1);
});

it('drops prompts with an unknown topic, intent or funnel stage, or text that is missing or too short', function () {
    $good = manyPrompts(1)[0];
    $data = brandData(['prompts' => [
        $good,
        [...$good, 'cross_market_key' => 'finnes_ikke'],
        [...$good, 'intent' => 'annet'],
        [...$good, 'funnel_stage' => 'sluttfase'],
        [...$good, 'text' => 'ok'],
        [...$good, 'text' => 42],
        'ikke en prompt',
    ]]);

    $result = parseBrand(json_encode($data));

    expect($result['proposal']['prompts'])->toHaveCount(1)->and($result['warnings'])->toBe(['prompt_dropped']);
});

it('removes duplicate prompts, ignoring case and punctuation', function () {
    $good = manyPrompts(1)[0];
    $data = brandData(['prompts' => [$good, [...$good, 'text' => strtoupper($good['text']).'?'], [...$good, 'text' => 'Et helt annet spørsmål om sko']]]);

    expect(parseBrand(json_encode($data))['proposal']['prompts'])->toHaveCount(2);
});

it('cleans text from the model: control characters, whitespace and length', function () {
    $data = brandData([
        'industry' => "  Retail\u{0000}\n\n  og  mer ".str_repeat('x', 500),
        'prompts' => [['text' => "Hva er \u{202E}det beste\n\n valget for sko? ".str_repeat('z', 400), 'cross_market_key' => 'Running Shoes!', 'intent' => 'category', 'funnel_stage' => 'awareness', 'is_core' => false]],
    ]);

    $proposal = parseBrand(json_encode($data))['proposal'];

    expect(mb_strlen($proposal['industry']))->toBe(120)
        ->and($proposal['industry'])->toStartWith('Retail og mer')
        ->and($proposal['prompts'][0]['cross_market_key'])->toBe('running_shoes')
        ->and(mb_strlen($proposal['prompts'][0]['text']))->toBeLessThanOrEqual(300)
        ->and($proposal['prompts'][0]['text'])->not->toContain("\n")->not->toContain("\u{202E}");
});

it('reads market prompts only for the topics the brand already has', function () {
    $json = OnboardingFakes::marketJson(['running_shoes', 'ukjent', 'shoe_stores']);

    $result = (new ProposalParser)->parseMarket($json, ['running_shoes', 'shoe_stores'], 50);

    expect($result['proposal']['prompts'])->toHaveCount(2)
        ->and(array_column($result['proposal']['prompts'], 'cross_market_key'))->toBe(['running_shoes', 'shoe_stores'])
        ->and($result['warnings'])->toBe(['prompt_dropped']);
});

it('keeps an existing topic key exactly as it is stored, even when it is not in the normal form', function () {
    $json = json_encode(['prompts' => [['text' => 'Vilken löparsko är bäst för nybörjare?', 'cross_market_key' => 'Running-Shoes', 'intent' => 'problem', 'funnel_stage' => 'awareness', 'is_core' => false]]]);

    expect((new ProposalParser)->parseMarket($json, ['Running-Shoes'], 50)['proposal']['prompts'][0]['cross_market_key'])->toBe('Running-Shoes');
});

it('limits market prompts to what the plan allows', function () {
    $data = ['prompts' => manyPrompts(40)];

    expect((new ProposalParser)->parseMarket(json_encode($data), ['running_shoes'], 12)['proposal']['prompts'])->toHaveCount(12)
        ->and((new ProposalParser)->parseMarket('nope', ['running_shoes'], 12))->toBeNull()
        ->and((new ProposalParser)->parseMarket('{"x":1}', ['running_shoes'], 12))->toBeNull();
});
