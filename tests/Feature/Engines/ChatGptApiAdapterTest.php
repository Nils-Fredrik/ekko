<?php

use App\Engines\Adapters\ChatGptApiAdapter;
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
    config(['engines.chatgpt_api.model' => 'gpt-5-mini']);
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

it('collects a fresh localized Responses request and preserves provider annotations', function (
    string $fixtureName,
    string $country,
    string $language,
    string $city,
    string $prompt,
    string $byok,
) {
    $raw = EngineFixtures::load('chatgpt_api', $fixtureName);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($raw)]);
    Engine::factory()->create(['key' => 'chatgpt_api', 'model_id' => 'gpt-5-mini-2025-08-07']);

    $adapter = app(EngineRegistry::class)->resolve('chatgpt_api');
    $market = new Market(['country' => $country, 'language' => $language, 'location_label' => $city]);

    expect($adapter)->toBeInstanceOf(ChatGptApiAdapter::class)
        ->and($adapter->supports($market))->toBeTrue();

    $result = $adapter->collect(new EngineRequest($prompt, $country, $language, $city, '15', $byok));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer '.$byok)
        && $request->data() === [
            'model' => 'gpt-5-mini-2025-08-07',
            'input' => $prompt."\n\nRespond in the language identified by the BCP 47 tag {$language}.",
            'store' => false,
            'tools' => [[
                'type' => 'web_search',
                'user_location' => ['type' => 'approximate', 'country' => $country, 'city' => $city],
            ]],
            'tool_choice' => 'required',
        ]);

    expect($result->raw)->toBe($raw)
        ->and($result->modelIdReported)->toBe('gpt-5-mini-2025-08-07')
        ->and($result->searchCalls)->toBe(1)
        ->and($result->inputTokens)->toBe($raw['usage']['input_tokens'])
        ->and($result->outputTokens)->toBe($raw['usage']['output_tokens']);

    if ($country === 'NO') {
        expect($result->answerText)->toBe("Et norsk eksempel.\n\nEt annet eksempel.")
            ->and($result->citations)->toBe([
                ['url' => 'https://example.test/oslo', 'title' => 'Oslo', 'rank' => 1, 'from_annotation' => true],
                ['url' => 'https://example.test/norge', 'title' => 'Norge', 'rank' => 2, 'from_annotation' => true],
            ]);
    } else {
        expect($result->answerText)->toBe('Ett svenskt exempel utan källor.')
            ->and($result->citations)->toBe([]);
    }
})->with([
    'Norwegian / citations' => ['no-nb', 'NO', 'nb', 'Oslo', 'Vis et eksempel.', 'fixture-norway'],
    'Swedish / no citations' => ['se-sv-no-citations', 'SE', 'sv', 'Göteborg', 'Visa ett exempel.', 'fixture-byok'],
]);

it('backs off on 429 and 5xx and retries the same request up to three times', function () {
    Engine::factory()->create(['key' => 'chatgpt_api', 'model_id' => '']);
    $failure = EngineFixtures::load('chatgpt_api', 'error');
    $raw = EngineFixtures::load('chatgpt_api', 'no-nb');
    Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
        ->push($failure, 429)
        ->push($failure, 503)
        ->push($raw)]);

    $result = app(ChatGptApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-byok'));

    expect($result->raw)->toBe($raw);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'gpt-5-mini'
        && $request['tools'][0]['user_location'] === ['type' => 'approximate', 'country' => 'NO']);
    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(500)->milliseconds()]);
});

it('stops on a permanent 4xx and records a sanitized failure code', function () {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(EngineFixtures::load('chatgpt_api', 'error'), 401)]);

    expect(fn () => app(ChatGptApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-byok')))
        ->toThrow(EngineCallFailed::class, 'openai_http_401');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('stops after three unsuccessful transient responses', function () {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(EngineFixtures::load('chatgpt_api', 'error'), 502)]);

    expect(fn () => app(ChatGptApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-byok')))
        ->toThrow(EngineCallFailed::class, 'openai_http_502');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('handles a transport timeout without repeating or surfacing provider details', function () {
    Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection('Synthetic timeout')]);

    expect(fn () => app(ChatGptApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-byok')))
        ->toThrow(EngineCallFailed::class, 'openai_connection_failed');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('rejects an empty or incomplete provider response', function (string $status) {
    $raw = EngineFixtures::load('chatgpt_api', 'empty-response');
    $raw['status'] = $status;
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($raw)]);

    expect(fn () => app(ChatGptApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', 'fixture-byok')))
        ->toThrow(EngineCallFailed::class, 'openai_invalid_response');

    Http::assertSentCount(1);
})->with(['completed', 'incomplete']);

it('requires a tenant key before making a request', function (?string $apiKey) {
    Http::fake();

    expect(fn () => app(ChatGptApiAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', $apiKey)))
        ->toThrow(EngineCallFailed::class, 'openai_missing_api_key');

    Http::assertNothingSent();
})->with([null, '   ']);
