<?php

use App\Engines\Adapters\ClaudeHaikuAdapter;
use App\Engines\Adapters\ClaudeSonnetAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRegistry;
use App\Engines\EngineRequest;
use App\Models\Engine;
use App\Models\Market;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Engines\EngineFixtures;

const ANTHROPIC_MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

dataset('claude engines', [
    'Haiku' => ['claude_haiku', ClaudeHaikuAdapter::class, 'claude-haiku-5-5'],
    'Sonnet' => ['claude_sonnet', ClaudeSonnetAdapter::class, 'claude-sonnet-5-5'],
]);

beforeEach(function () {
    config(['services.anthropic.key' => 'fixture-platform']);
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

it('collects a fresh localized Messages request and preserves provider annotations', function (
    string $key,
    string $adapterClass,
    string $model,
    string $fixture,
    string $country,
    string $language,
    string $city,
    string $prompt,
    ?string $apiKey,
    string $expectedText,
    int $expectedSearchCalls,
    array $expectedCitations,
) {
    $raw = EngineFixtures::load('claude', $fixture);
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::response($raw)]);

    $adapter = app(EngineRegistry::class)->resolve($key);
    $market = new Market(['country' => $country, 'language' => $language, 'location_label' => $city]);

    expect($adapter)->toBeInstanceOf($adapterClass)
        ->and($adapter->supports($market))->toBeTrue();

    $result = $adapter->collect(new EngineRequest($prompt, $country, $language, $city, '15', $apiKey));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('x-api-key', $apiKey ?? 'fixture-platform')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request->data() === [
            'model' => $model,
            'max_tokens' => 4096,
            'messages' => [[
                'role' => 'user',
                'content' => $prompt."\n\nRespond in the language identified by the BCP 47 tag {$language}.",
            ]],
            'tools' => [[
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                'max_uses' => 2,
                'user_location' => ['type' => 'approximate', 'country' => $country, 'city' => $city],
            ]],
        ]);

    expect($result->raw)->toBe($raw)
        ->and($result->answerText)->toBe($expectedText)
        ->and($result->modelIdReported)->toBe($raw['model'])
        ->and($result->citations)->toBe($expectedCitations)
        ->and($result->searchCalls)->toBe($expectedSearchCalls)
        ->and($result->inputTokens)->toBe($raw['usage']['input_tokens'])
        ->and($result->outputTokens)->toBe($raw['usage']['output_tokens']);
})->with('claude engines')->with([
    'Norwegian with citations' => [
        'no-nb', 'NO', 'nb', 'Oslo', 'Vis et eksempel.', null, "Jeg søker etter Oslo\n\nOslo er et norsk eksempel. Her er et annet eksempel.", 2, [
            ['url' => 'https://example.test/oslo', 'title' => 'Oslo', 'rank' => 1, 'from_annotation' => true],
            ['url' => 'https://example.test/norge', 'title' => 'Norge', 'rank' => 2, 'from_annotation' => true],
        ],
    ],
    'Swedish without citations using BYOK' => [
        'se-sv-no-citations', 'SE', 'sv', 'Göteborg', 'Visa ett exempel.',
        'fixture-byok', 'Ett svenskt exempel utan källor.', 0, [],
    ],
]);

it('backs off on 429 and overload, retaining the configured model and omitting an absent city', function (
    string $key,
    string $adapterClass,
    string $model,
) {
    $configuredModel = $model.'-fixture';
    Engine::factory()->create(['key' => $key, 'model_id' => $configuredModel]);
    $raw = EngineFixtures::load('claude', 'no-nb');
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::sequence()
        ->push(EngineFixtures::load('claude', 'rate-limit'), 429)
        ->push(EngineFixtures::load('claude', 'overload'), 529)
        ->push($raw)]);

    $result = app($adapterClass)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15'));

    expect($result->raw)->toBe($raw);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['model'] === $configuredModel
        && $request['tools'][0]['user_location'] === ['type' => 'approximate', 'country' => 'NO']);
    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(500)->milliseconds()]);
})->with('claude engines');

it('stops after three unsuccessful transient responses', function (string $fixture, int $status) {
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::response(EngineFixtures::load('claude', $fixture), $status)]);

    expect(fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15')))
        ->toThrow(EngineCallFailed::class, 'anthropic_http_'.$status);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
})->with([
    'rate limit' => ['rate-limit', 429],
    'overload' => ['overload', 529],
]);

it('stops on a permanent 4xx and records a sanitized failure code', function (int $status) {
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::response([
        'type' => 'error',
        'error' => ['message' => 'Synthetic provider detail.'],
    ], $status)]);

    expect(fn () => app(ClaudeSonnetAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15')))
        ->toThrow(EngineCallFailed::class, 'anthropic_http_'.$status);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([401, 402]);

it('handles a transport timeout without repeating or exposing provider details', function (
    string $key,
) {
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::failedConnection('Synthetic timeout')]);

    $adapter = app(EngineRegistry::class)->resolve($key);

    expect(fn () => $adapter->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15')))
        ->toThrow(EngineCallFailed::class, 'anthropic_connection_failed');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with('claude engines');

it('requires either a platform key or a tenant key before making a request', function (?string $platformKey, ?string $apiKey) {
    config(['services.anthropic.key' => $platformKey]);
    Http::fake();

    expect(fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15', $apiKey)))
        ->toThrow(EngineCallFailed::class, 'anthropic_missing_api_key');

    Http::assertNothingSent();
})->with([
    'missing key' => [null, null],
    'blank tenant key' => ['fixture-platform', '   '],
]);

it('rejects an empty or unfinished response', function (string $stopReason, array $content) {
    $raw = EngineFixtures::load('claude', 'no-nb');
    $raw['stop_reason'] = $stopReason;
    $raw['content'] = $content;
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::response($raw)]);

    expect(fn () => app(ClaudeSonnetAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15')))
        ->toThrow(EngineCallFailed::class, 'anthropic_invalid_response');
})->with([
    'empty' => ['end_turn', []],
    'paused' => ['pause_turn', [['type' => 'text', 'text' => 'Synthetic preamble.']]],
    'truncated' => ['max_tokens', [['type' => 'text', 'text' => 'Synthetic partial answer.']]],
    'refused' => ['refusal', [['type' => 'text', 'text' => 'Synthetic refusal.']]],
]);

it('distinguishes a search failure from reaching the search cap inside an HTTP success', function (
    string $errorCode,
    bool $failed,
) {
    $raw = EngineFixtures::load('claude', 'no-nb');
    $raw['content'][] = [
        'type' => 'web_search_tool_result',
        'tool_use_id' => 'srvtoolu_fixture',
        'content' => ['type' => 'web_search_tool_result_error', 'error_code' => $errorCode],
    ];
    Http::fake([ANTHROPIC_MESSAGES_URL => Http::response($raw)]);

    $collect = fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Eksempel', 'NO', 'nb', null, '15'));

    if ($failed) {
        expect($collect)->toThrow(EngineCallFailed::class, 'anthropic_web_search_failed');
    } else {
        $result = $collect();
        expect($result->searchCalls)->toBe(2)
            ->and($result->citations)->toHaveCount(2);
    }

    Http::assertSentCount(1);
})->with([
    'unavailable' => ['unavailable', true],
    'search cap' => ['max_uses_exceeded', false],
]);
