<?php

use App\Detection\DeterministicDetector;
use App\Engines\Adapters\GeminiApiAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRegistry;
use App\Engines\EngineRequest;
use App\Models\Brand;
use App\Models\Citation;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Response;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Engines\EngineFixtures;

beforeEach(function () {
    config(['engines.gemini_api.model' => 'gemini-3.8-flash']);
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

it('collects a fresh Gemini response with Google Search and market context', function (
    string $fixture,
    string $country,
    string $language,
    string $city,
    string $prompt,
    string $answer,
    int $searches,
    int $outputTokens,
    array $citations,
) {
    $raw = EngineFixtures::load('gemini_api', $fixture);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';
    Http::fake([$url => Http::response($raw)]);
    Engine::factory()->create(['key' => 'gemini_api', 'model_id' => 'models/gemini-flash-latest']);

    $adapter = app(EngineRegistry::class)->resolve('gemini_api');
    $market = new Market(['country' => $country, 'language' => $language, 'location_label' => $city]);

    expect($adapter)->toBeInstanceOf(GeminiApiAdapter::class)
        ->and($adapter->supports($market))->toBeTrue();

    $result = $adapter->collect(new EngineRequest($prompt, $country, $language, $city, '15', 'fixture-tenant-key'));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($url, $prompt, $country, $city, $language): bool {
        $text = $prompt."\n\nUse country code {$country}, city {$city} as the geographic context for searches and recommendations. "
            ."Respond in the language identified by the BCP 47 tag {$language}.";

        return $request->method() === 'POST'
            && $request->url() === $url
            && $request->hasHeader('x-goog-api-key', 'fixture-tenant-key')
            && $request->body() === json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
                'tools' => [['googleSearch' => (object) []]],
            ], JSON_THROW_ON_ERROR);
    });

    expect($result->raw)->toBe($raw)
        ->and($result->answerText)->toBe($answer)
        ->and($result->modelIdReported)->toBe('gemini-3.8-flash')
        ->and($result->searchCalls)->toBe($searches)
        ->and($result->inputTokens)->toBe($raw['usageMetadata']['promptTokenCount'])
        ->and($result->outputTokens)->toBe($outputTokens)
        ->and($result->citations)->toBe($citations);
})->with([
    'NO/nb with sources' => [
        'no-nb', 'NO', 'nb', 'Oslo', 'Hva kan jeg besøke i nærheten?',
        "Besøk Fjordparken i Oslo.\nKystmuseet er et annet alternativ.", 2, 40, [
            [
                'url' => 'https://fjordparken.test/',
                'title' => 'www.Fjordparken.test',
                'rank' => 1,
                'from_annotation' => true,
            ],
            ['url' => 'https://example.test/kystmuseet', 'title' => null, 'rank' => 2, 'from_annotation' => true],
        ],
    ],
    'SE/sv without sources' => [
        'se-sv-no-citations', 'SE', 'sv', 'Göteborg', 'Vad kan jag besöka i närheten?',
        'Ett svenskt exempel utan källor.', 0, 10, [],
    ],
]);

it('classifies a grounded source against the tenant domain using the provider metadata', function (?string $providerDomain, string $domain) {
    $raw = EngineFixtures::load('gemini_api', 'no-nb');
    if ($providerDomain !== null) {
        $raw['candidates'][0]['groundingMetadata']['groundingChunks'][0]['web']['domain'] = $providerDomain;
    }
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response($raw)]);

    $result = app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', 'Oslo', '15', 'fixture-tenant-key'));
    $source = $result->citations[0];

    expect($source['url'])->toBe("https://{$domain}/")
        ->and($result->raw)->toBe($raw);
    Http::assertSentCount(1);

    $tenant = Team::factory()->tenant()->create();
    app(TenantContext::class)->run($tenant->id, function () use ($source, $result, $domain) {
        $brand = Brand::factory()->create();
        $brand->domains()->create(['domain' => $domain, 'include_subdomains' => false]);
        $response = Response::factory()->create(['answer_text' => $result->answerText]);
        $citation = Citation::factory()->for($response)->create([
            'url' => $source['url'],
            'rank_in_answer' => $source['rank'],
            'from_annotation' => $source['from_annotation'],
        ]);

        app(DeterministicDetector::class)->detect($response);

        $citation->refresh();
        expect($citation->domain)->toBe($domain)
            ->and($citation->ownership)->toBe('own')
            ->and($citation->from_annotation)->toBeTrue();
    });
})->with([
    'hostname in the title' => [null, 'fjordparken.test'],
    'structured domain takes precedence' => ['www.Example.test.', 'example.test'],
]);

it('retries 429 and 5xx with backoff and uses the configured fallback model', function () {
    Engine::factory()->create(['key' => 'gemini_api', 'model_id' => '']);
    $raw = EngineFixtures::load('gemini_api', 'no-nb');
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::sequence()
        ->push(EngineFixtures::load('gemini_api', 'rate-limited'), 429)
        ->push(EngineFixtures::load('gemini_api', 'unavailable'), 503)
        ->push($raw)]);

    $result = app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-tenant-key'));

    expect($result->raw)->toBe($raw);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['contents'][0]['parts'][0]['text']
        === "Eksempel\n\nUse country code NO as the geographic context for searches and recommendations. "
            .'Respond in the language identified by the BCP 47 tag nb.');
    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(500)->milliseconds()]);
});

it('reports a sanitized HTTP failure after the allowed attempts', function (int $status, int $attempts) {
    $raw = EngineFixtures::load('gemini_api', 'unavailable');
    $raw['error']['code'] = $status;
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response($raw, $status)]);

    expect(fn () => app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-tenant-key')))
        ->toThrow(EngineCallFailed::class, 'gemini_http_'.$status);

    Http::assertSentCount($attempts);
    Sleep::assertSleptTimes($attempts - 1);
})->with([[403, 1], [503, 3]]);

it('normalizes a connection timeout and sets a two-minute request limit', function () {
    Http::fake(function (Request $request, array $options) {
        expect($options['timeout'])->toBe(120)
            ->and($options['allow_redirects'])->toBeFalse();

        return Http::failedConnection('Synthetic timeout')($request);
    });

    expect(fn () => app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-tenant-key')))
        ->toThrow(EngineCallFailed::class, 'gemini_connection_failed');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('rejects an empty provider response', function () {
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response(EngineFixtures::load('gemini_api', 'empty-response'))]);

    expect(fn () => app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-tenant-key')))
        ->toThrow(EngineCallFailed::class, 'gemini_invalid_response');

    Http::assertSentCount(1);
});

it('rejects a partial answer unless Gemini reports a normal stop', function (array $completion) {
    $raw = EngineFixtures::load('gemini_api', 'se-sv-no-citations');
    unset($raw['candidates'][0]['finishReason']);
    $raw['candidates'][0] = array_replace($raw['candidates'][0], $completion);
    $raw['candidates'][0]['content']['parts'] = [['text' => '1. Fjordparken 2.']];
    Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response($raw)]);

    expect(fn () => app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-tenant-key')))
        ->toThrow(fn (EngineCallFailed $failure) => expect($failure->errorCode)->toBe('gemini_invalid_response')
            ->and($failure->getMessage())->toBe('gemini_invalid_response'));

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'token limit' => [['finishReason' => 'MAX_TOKENS', 'finishMessage' => 'Synthetic provider detail']],
    'safety stop' => [['finishReason' => 'SAFETY']],
    'empty finish reason' => [['finishReason' => '']],
    'missing finish reason' => [[]],
]);

it('requires a tenant API key before making a request', function (?string $apiKey) {
    Http::fake();

    expect(fn () => app(GeminiApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', $apiKey)))
        ->toThrow(EngineCallFailed::class, 'gemini_missing_api_key');

    Http::assertNothingSent();
})->with([null, '   ']);
