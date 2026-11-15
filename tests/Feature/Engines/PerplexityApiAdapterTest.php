<?php

use App\Engines\Adapters\PerplexityApiAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRegistry;
use App\Engines\EngineRequest;
use App\Models\Engine;
use App\Models\Market;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Engines\EngineFixtures;

beforeEach(function () {
    config([
        'engines.perplexity_api.api_key' => 'fixture-platform-key',
        'engines.perplexity_api.model' => 'perplexity/sonar',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

it('collects localized Sonar answers with structured sources and usage', function (
    string $fixture,
    string $country,
    string $language,
    string $city,
    string $prompt,
    ?string $byok,
    string $answer,
    array $citations,
    int $searchCalls,
) {
    $raw = EngineFixtures::load('perplexity_api', $fixture);
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($raw)]);
    $adapter = app(EngineRegistry::class)->resolve('perplexity_api');

    expect($adapter)->toBeInstanceOf(PerplexityApiAdapter::class)
        ->and($adapter->supports(new Market(['country' => $country, 'language' => $language])))->toBeTrue();

    $result = $adapter->collect(new EngineRequest($prompt, $country, $language, $city, '12', $byok));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer '.($byok ?? 'fixture-platform-key'))
        && $request->data() === [
            'model' => 'perplexity/sonar',
            'input' => $prompt."\n\nRespond in the language identified by the BCP 47 tag {$language}.",
            'store' => false,
            'tools' => [['type' => 'web_search', 'user_location' => ['country' => $country, 'city' => $city]]],
            'tool_choice' => 'required',
        ]);

    expect($result->answerText)->toBe($answer)
        ->and($result->modelIdReported)->toBe('perplexity/sonar')
        ->and($result->citations)->toBe($citations)
        ->and($result->searchCalls)->toBe($searchCalls)
        ->and($result->inputTokens)->toBe($raw['usage']['input_tokens'])
        ->and($result->outputTokens)->toBe($raw['usage']['output_tokens'])
        ->and($result->raw)->toBe($raw);
})->with([
    'NO/nb' => [
        'no-nb', 'NO', 'nb', 'Oslo', 'Finn sykkelruter.', null,
        "Oslo har flere merkede sykkelruter.[1]\n\nDu finner turforslag hos lokale sykkelorganisasjoner.[2]",
        [
            ['url' => 'https://example.test/oslo', 'title' => 'Sykkelruter i Oslo', 'rank' => 1, 'from_annotation' => true],
            ['url' => 'https://example.test/norge', 'title' => 'Sykling i Norge', 'rank' => 2, 'from_annotation' => true],
            ['url' => 'https://example.test/oslo/kart', 'title' => 'Sykkelkart', 'rank' => 3, 'from_annotation' => true],
        ],
        1,
    ],
    'SE/sv' => [
        'se-sv', 'SE', 'sv', 'Göteborg', 'Hitta cykelleder.', 'fixture-tenant-key',
        'I Göteborg finns cykelleder längs kusten.[1]',
        [['url' => 'https://example.test/goteborg', 'title' => 'Cykelleder i Göteborg', 'rank' => 1, 'from_annotation' => true]],
        2,
    ],
]);

it('returns an answer without citations and uses the engine model override', function () {
    Engine::factory()->create(['key' => 'perplexity_api', 'model_id' => 'openai/gpt-5.6-luna']);
    $raw = EngineFixtures::load('perplexity_api', 'no-citations');
    $raw['model'] = 'openai/gpt-5.6-luna';
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($raw)]);

    $result = app(PerplexityApiAdapter::class)->collect(new EngineRequest('Finn en nybegynnertur.', 'NO', 'nb', null, '12'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'openai/gpt-5.6-luna'
        && $request['tools'][0]['user_location'] === ['country' => 'NO']);
    expect($result->answerText)->toBe('En kort tur på flat vei kan fungere.')
        ->and($result->modelIdReported)->toBe('openai/gpt-5.6-luna')
        ->and($result->citations)->toBe([])
        ->and($result->searchCalls)->toBe(1)
        ->and($result->raw)->toBe($raw);
});

it('retries 429 and 5xx responses with exponential backoff', function () {
    $error = EngineFixtures::load('perplexity_api', 'error');
    $raw = EngineFixtures::load('perplexity_api', 'no-citations');
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::sequence()
        ->push($error, 429)
        ->push($error, 503)
        ->push($raw)]);

    $result = app(PerplexityApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '12'));

    expect($result->raw)->toBe($raw);
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(500)->milliseconds()]);
});

it('reports sanitized HTTP failures after the allowed attempts', function (int $status, int $attempts) {
    $error = EngineFixtures::load('perplexity_api', 'error');
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($error, $status)]);

    expect(fn () => app(PerplexityApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '12')))
        ->toThrow(EngineCallFailed::class, 'perplexity_http_'.$status);

    Http::assertSentCount($attempts);
    Sleep::assertSleptTimes($attempts - 1);
})->with([[429, 3], [502, 3], [401, 1]]);

it('reports a transport timeout without repeating the request', function () {
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::failedConnection('Synthetic timeout')]);

    expect(fn () => app(PerplexityApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '12')))
        ->toThrow(EngineCallFailed::class, 'perplexity_connection_failed');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('rejects an unsuccessful Agent API response without leaking its error', function () {
    $raw = EngineFixtures::load('perplexity_api', 'no-citations');
    $raw['status'] = 'failed';
    $raw['error'] = ['message' => 'Synthetic provider error'];
    Http::fake(['https://api.perplexity.ai/v1/agent' => Http::response($raw)]);

    expect(fn () => app(PerplexityApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '12')))
        ->toThrow(EngineCallFailed::class, 'perplexity_invalid_response');

    Http::assertSentCount(1);
});

it('rejects missing platform credentials and blank tenant keys before making a request', function (string $platformKey, ?string $tenantKey) {
    config(['engines.perplexity_api.api_key' => $platformKey]);
    Http::fake();

    expect(fn () => app(PerplexityApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '12', $tenantKey)))
        ->toThrow(EngineCallFailed::class, 'perplexity_missing_api_key');

    Http::assertNothingSent();
})->with([['', null], ['fixture-platform-key', '   ']]);
