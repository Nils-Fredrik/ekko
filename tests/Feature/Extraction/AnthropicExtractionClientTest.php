<?php

// The real client class against canned HTTP responses: no network, no key (the SDK takes a PSR-18 transporter).

use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionNotConfiguredException;
use App\Extraction\ExtractionRequest;
use Tests\Support\Extraction\FakeHttpClient;

function extractionJson(): string
{
    return json_encode(['mentions' => [], 'citations' => [], 'answer_type' => 'informational']);
}

function sdkClient(FakeHttpClient $http): AnthropicExtractionClient
{
    config(['services.anthropic.key' => 'test-key-not-real']);

    return AnthropicExtractionClient::fromConfig($http);
}

it('refuses to build without an API key', function () {
    config(['services.anthropic.key' => null]);

    AnthropicExtractionClient::fromConfig();
})->throws(ExtractionNotConfiguredException::class);

it('submits one batch with the schema as structured output, Haiku 5.5 and no forced tool', function () {
    $http = new FakeHttpClient(['POST /v1/messages/batches' => [200, FakeHttpClient::batch('in_progress')]]);

    $id = sdkClient($http)->createBatch([
        new ExtractionRequest('r1', 'system text', 'user one'),
        new ExtractionRequest('r2', 'system text', 'user two'),
    ]);

    $body = $http->jsonBody(0);
    expect($id)->toBe('msgbatch_1')
        ->and($body['requests'])->toHaveCount(2)
        ->and($body['requests'][0]['custom_id'])->toBe('r1')
        ->and($body['requests'][1]['custom_id'])->toBe('r2');

    $params = $body['requests'][0]['params'];
    expect($params['model'])->toBe('claude-haiku-5-5')
        ->and($params['max_tokens'])->toBe(16000)
        ->and($params['system'])->toBe('system text')
        ->and($params['messages'])->toBe([['role' => 'user', 'content' => 'user one']])
        ->and($params['output_config']['effort'])->toBe('low')
        ->and($params['output_config']['format']['type'])->toBe('json_schema')
        ->and($params['output_config']['format']['schema']['required'])->toBe(['mentions', 'citations', 'answer_type'])
        ->and($params['output_config']['format']['schema']['properties']['answer_type']['enum'])->toContain('refusal')
        ->and($params)->not->toHaveKeys(['tool_choice', 'tools', 'temperature', 'top_p', 'top_k', 'thinking']);
    expect($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer test-key-not-real');
});

it('reports whether the batch has ended', function () {
    $http = new FakeHttpClient([
        'GET /v1/messages/batches/msgbatch_1' => [200, FakeHttpClient::batch('in_progress')],
    ]);
    expect(sdkClient($http)->batchEnded('msgbatch_1'))->toBeFalse();

    $http = new FakeHttpClient([
        'GET /v1/messages/batches/msgbatch_1' => [200, FakeHttpClient::batch('ended')],
    ]);
    expect(sdkClient($http)->batchEnded('msgbatch_1'))->toBeTrue();
});

it('reads batch results of every kind, with token usage', function () {
    $lines = [
        ['custom_id' => 'r1', 'result' => ['type' => 'succeeded', 'message' => json_decode(FakeHttpClient::message(extractionJson(), 'end_turn', 120, 30), true)]],
        ['custom_id' => 'r2', 'result' => ['type' => 'succeeded', 'message' => json_decode(FakeHttpClient::message('', 'refusal', 90, 0), true)]],
        ['custom_id' => 'r3', 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'details that are never kept']]]],
        ['custom_id' => 'r4', 'result' => ['type' => 'expired']],
        ['custom_id' => 'r5', 'result' => ['type' => 'succeeded', 'message' => json_decode(FakeHttpClient::message('{"mentions": [', 'max_tokens', 80, 16000), true)]],
    ];
    $http = new FakeHttpClient([
        'GET /v1/messages/batches/msgbatch_1/results' => [200, implode("\n", array_map('json_encode', $lines))."\n", 'application/x-jsonl'],
    ]);

    $outcomes = [];
    foreach (sdkClient($http)->batchResults('msgbatch_1') as $outcome) {
        $outcomes[$outcome->customId] = $outcome;
    }

    expect($outcomes)->toHaveCount(5)
        ->and($outcomes['r5']->succeeded)->toBeFalse()
        ->and($outcomes['r5']->text)->toBeNull()
        ->and($outcomes['r5']->errorType)->toBe('max_tokens')
        ->and($outcomes['r5']->outputTokens)->toBe(16000)
        ->and($outcomes['r1']->succeeded)->toBeTrue()
        ->and($outcomes['r1']->text)->toBe(extractionJson())
        ->and($outcomes['r1']->inputTokens)->toBe(120)
        ->and($outcomes['r1']->outputTokens)->toBe(30)
        ->and($outcomes['r1']->viaBatch)->toBeTrue()
        ->and($outcomes['r2']->succeeded)->toBeFalse()
        ->and($outcomes['r2']->errorType)->toBe('refusal')
        ->and($outcomes['r2']->inputTokens)->toBe(90)
        ->and($outcomes['r3']->succeeded)->toBeFalse()
        ->and($outcomes['r3']->errorType)->toBe('invalid_request_error')
        ->and($outcomes['r3']->inputTokens)->toBe(0)
        ->and($outcomes['r4']->errorType)->toBe('expired');
});

it('sends the single retry through the Messages API with the same parameters, not as a batch', function () {
    $http = new FakeHttpClient(['POST /v1/messages' => [200, FakeHttpClient::message(extractionJson(), 'end_turn', 50, 20)]]);

    $outcome = sdkClient($http)->message(new ExtractionRequest('r9', 'system text', 'user nine'));

    $body = $http->jsonBody(0);
    expect($outcome->customId)->toBe('r9')
        ->and($outcome->viaBatch)->toBeFalse()
        ->and($outcome->text)->toBe(extractionJson())
        ->and($outcome->inputTokens)->toBe(50)
        ->and($body['model'])->toBe('claude-haiku-5-5')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body)->not->toHaveKeys(['tool_choice', 'tools']);
});
