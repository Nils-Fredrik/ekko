<?php

use App\Engines\Adapters\DataForSeoQueuedAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRegistry;
use App\Engines\EngineRequest;
use App\Engines\QueuedEngineResubmission;
use App\Models\Market;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Engines\EngineFixtures;

dataset('dataforseo engines', [
    'ChatGPT' => ['chatgpt_ui', 'ai_optimization/chat_gpt/llm_scraper', 'gpt-fixture', null],
    'Gemini' => ['gemini_ui', 'ai_optimization/gemini/llm_scraper', 'gemini-fixture', 'ai_optimization/gemini/llm_scraper/locations'],
    'Google AI Overviews' => ['google_aio', 'serp/google/organic', null, 'serp/google/locations'],
    'Google AI Mode' => ['google_ai_mode', 'serp/google/ai_mode', null, 'serp/google/locations'],
]);

beforeEach(function () {
    config(['engines.dataforseo.credentials' => 'fixture-login:fixture-password']);
    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

it('submits a localized task and retrieves the original result with annotations', function (
    string $engine,
    string $path,
    ?string $model,
    ?string $locationsPath,
    string $country,
    string $language,
    string $fixture,
    string $text,
) {
    $url = "https://api.dataforseo.com/v3/{$path}";
    $id = '11150800-0000-0000-0000-123456789abc';
    $raw = EngineFixtures::load($engine, $fixture);
    $responses = [
        "{$url}/task_post" => Http::response(EngineFixtures::load('dataforseo', 'created')),
        "{$url}/tasks_ready" => Http::response(EngineFixtures::load('dataforseo', 'ready')),
        "{$url}/task_get/advanced/{$id}" => Http::response($raw),
    ];

    $city = $country === 'NO' ? 'Oslo' : null;

    if ($locationsPath !== null && $city !== null) {
        $responses["https://api.dataforseo.com/v3/{$locationsPath}/NO"] = Http::response(
            EngineFixtures::load('dataforseo', 'locations-no'),
        );
    }

    Http::fake($responses);

    $adapter = app(EngineRegistry::class)->resolve($engine);
    $market = new Market(['country' => $country, 'language' => $language, 'location_label' => $city]);

    expect($adapter)->toBeInstanceOf(DataForSeoQueuedAdapter::class)
        ->and($adapter->supports($market))->toBeTrue()
        ->and($adapter->submit(new EngineRequest('Rundtur + 50%?', $country, $language, $city, '51')))->toBe($id)
        ->and($adapter->tasksReady())->toBe([$id]);

    $result = $adapter->fetch($id);
    $expectedLocation = $country === 'SE' ? 2752 : ($locationsPath === null ? 2578 : 1010952);
    $expectedLanguage = $country === 'SE' ? 'sv' : 'no';
    $auth = 'Basic '.base64_encode('fixture-login:fixture-password');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', $auth)
        && $request->url() === "{$url}/task_post"
        && $request->data() === [[
            'keyword' => 'Rundtur %2B 50%25?',
            'priority' => 1,
            'tag' => '51',
            'location_code' => $expectedLocation,
            'language_code' => $expectedLanguage,
        ] + ($engine === 'google_aio' ? ['load_async_ai_overview' => true] : [])]);

    expect($result->raw)->toBe($raw)
        ->and($result->modelIdReported)->toBe($model)
        ->and($result->answerText)->toBe($text)
        ->and($result->inputTokens)->toBe(0)
        ->and($result->outputTokens)->toBe(0)
        ->and($result->searchCalls)->toBe(0)
        ->and($result->citations)->toBe($country === 'NO' ? [
            ['url' => 'https://example.test/guide', 'title' => 'Eksempelguiden', 'rank' => 1, 'from_annotation' => true],
            ['url' => 'https://example.test/map', 'title' => 'Kart', 'rank' => 2, 'from_annotation' => true],
        ] : []);

    Http::assertSentCount($country === 'NO' && $locationsPath !== null ? 4 : 3);
})->with('dataforseo engines')->with([
    'NO/nb' => ['NO', 'nb', 'no-nb', 'En norsk guide til sykkelruter.'],
    'SE/sv' => ['SE', 'sv', 'se-sv-no-citations', 'En svensk guide till cykelvägar.'],
]);

it('waits on pending tasks and resubmits an expired submission once', function (string $engine, string $path) {
    $url = "https://api.dataforseo.com/v3/{$path}";
    $id = '11150800-0000-0000-0000-123456789abc';
    $replacement = '11151400-0000-0000-0000-123456789abc';
    Http::fake([
        "{$url}/task_post" => Http::sequence()
            ->push(EngineFixtures::load('dataforseo', 'created'))
            ->push(EngineFixtures::load('dataforseo', 'resubmitted')),
        "{$url}/task_get/advanced/{$id}" => Http::response(EngineFixtures::load('dataforseo', 'pending')),
    ]);

    $adapter = app(EngineRegistry::class)->resolve($engine);
    $resubmission = app(QueuedEngineResubmission::class);
    $request = new EngineRequest('Vis et eksempel.', 'NO', 'nb', null, '51');
    $submitted = CarbonImmutable::parse('2026-11-15 08:00:00', 'UTC');

    expect($adapter->submit($request))->toBe($id)
        ->and($adapter->fetch($id))->toBeNull();

    $this->travelTo($submitted->addHours(6)->subSecond(), function () use ($resubmission, $adapter, $request, $submitted) {
        expect($resubmission->resubmitPending($adapter, $request, $submitted, 1))->toBeNull();
        Http::assertSentCount(2);
    });

    $this->travelTo($submitted->addHours(6), function () use ($resubmission, $adapter, $request, $submitted, $replacement) {
        expect($resubmission->resubmitPending($adapter, $request, $submitted, 1))->toBe($replacement);
        Http::assertSentCount(3);
    });

    $this->travelTo($submitted->addHours(12), function () use ($resubmission, $adapter, $request, $submitted) {
        expect($resubmission->resubmitPending($adapter, $request, $submitted->addHours(6), 2))->toBeNull();
        Http::assertSentCount(3);
    });
})->with('dataforseo engines');

it('backs off on HTTP 429 and 5xx before returning a queued task ID', function (string $engine, string $path) {
    $fixture = EngineFixtures::load('dataforseo', 'error');
    Http::fake([
        "https://api.dataforseo.com/v3/{$path}/task_post" => Http::sequence()
            ->push($fixture, 429)
            ->push($fixture, 503)
            ->push(EngineFixtures::load('dataforseo', 'created')),
    ]);

    $adapter = app(EngineRegistry::class)->resolve($engine);
    expect($adapter->submit(new EngineRequest('Eksempel', 'NO', 'nb', null, '51')))
        ->toBe('11150800-0000-0000-0000-123456789abc');

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(500)->milliseconds()]);
})->with('dataforseo engines');

it('stops after three transient failures in DataForSEO envelopes', function (string $engine, string $path) {
    $fixture = EngineFixtures::load('dataforseo', 'error');
    $taskError = EngineFixtures::load('dataforseo', 'task-error');
    $taskError['tasks'][0]['status_code'] = 40202;
    Http::fake([
        "https://api.dataforseo.com/v3/{$path}/task_post" => Http::sequence()
            ->push($taskError)
            ->push($fixture)
            ->push($fixture),
    ]);

    $adapter = app(EngineRegistry::class)->resolve($engine);
    expect(fn () => $adapter->submit(new EngineRequest('Eksempel', 'NO', 'nb', null, '51')))
        ->toThrow(EngineCallFailed::class, 'dataforseo_status_50000');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
})->with('dataforseo engines');

it('surfaces permanent provider failures without repeating the paid request', function (string $engine, string $path) {
    Http::fake([
        "https://api.dataforseo.com/v3/{$path}/task_post" => Http::response(EngineFixtures::load('dataforseo', 'task-error')),
    ]);

    $adapter = app(EngineRegistry::class)->resolve($engine);
    expect(fn () => $adapter->submit(new EngineRequest('Eksempel', 'NO', 'nb', null, '51')))
        ->toThrow(EngineCallFailed::class, 'dataforseo_status_40501');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with('dataforseo engines');

it('bounds transport timeouts and preserves a sanitized failure code', function (string $engine, string $path) {
    $url = "https://api.dataforseo.com/v3/{$path}/task_post";
    Http::fake([
        $url => function (Request $request, array $options) use ($url) {
            expect($options['timeout'])->toBe(120);
            throw new ConnectException('Synthetic credentials and upstream detail', new PsrRequest('POST', $url));
        },
    ]);

    $adapter = app(EngineRegistry::class)->resolve($engine);
    expect(fn () => $adapter->submit(new EngineRequest('Eksempel', 'NO', 'nb', null, '51')))
        ->toThrow(EngineCallFailed::class, 'dataforseo_connection_failed');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with('dataforseo engines');

it('supports tenant credentials across submission and retrieval', function () {
    $url = 'https://api.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper';
    $id = '11150800-0000-0000-0000-123456789abc';
    Http::fake([
        "{$url}/task_post" => Http::response(EngineFixtures::load('dataforseo', 'created')),
        "{$url}/tasks_ready" => Http::response(EngineFixtures::load('dataforseo', 'ready')),
        "{$url}/task_get/advanced/{$id}" => Http::response(EngineFixtures::load('chatgpt_ui', 'no-nb')),
    ]);

    $adapter = app(EngineRegistry::class)->resolve('chatgpt_ui');
    $key = 'tenant-fixture:synthetic:password';
    $request = new EngineRequest('Eksempel', 'NO', 'nb', null, '51', $key);
    expect($adapter->submit($request))->toBe($id)
        ->and($adapter->tasksReady($key))->toBe([$id])
        ->and($adapter->fetch($id, $key)->answerText)->toBe('En norsk guide til sykkelruter.');

    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Basic '.base64_encode($key)));
});

it('records that Google displayed no AI answer while preserving the raw result', function () {
    $id = '11150800-0000-0000-0000-123456789abc';
    $raw = EngineFixtures::load('google_aio', 'no-nb');
    $raw['tasks'][0]['result'][0]['items'] = [
        ['type' => 'organic', 'title' => 'Klikk her', 'url' => 'https://example.test/organic'],
    ];
    Http::fake([
        "https://api.dataforseo.com/v3/serp/google/organic/task_get/advanced/{$id}" => Http::response($raw),
    ]);

    $result = app(EngineRegistry::class)->resolve('google_aio')->fetch($id);

    expect($result->answerText)->toBe('')
        ->and($result->citations)->toBe([])
        ->and($result->raw)->toBe($raw);
});

it('rejects unsupported markets and synchronous collection before sending an HTTP request', function (string $engine) {
    Http::fake();
    $adapter = app(EngineRegistry::class)->resolve($engine);
    $market = new Market(['country' => 'ZZ', 'language' => 'xx']);
    $request = new EngineRequest('Example', 'ZZ', 'xx', null, '51');

    expect($adapter->supports($market))->toBeFalse()
        ->and(fn () => $adapter->submit($request))->toThrow(EngineCallFailed::class, 'dataforseo_unsupported_market')
        ->and(fn () => $adapter->collect($request))->toThrow(LogicException::class);

    Http::assertNothingSent();
})->with('dataforseo engines');
