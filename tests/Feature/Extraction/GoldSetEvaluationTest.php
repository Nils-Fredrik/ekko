<?php

// The evaluation of model-based extraction against the gold set (docs/spec.md 18, T20). Runs against a recording
// of synthetic model output, with no network and no key. The real model is evaluated by the owner with
// `php artisan ekko:extraction:eval` (see ADR 0011).

use App\Extraction\Evaluation\GoldSetEvaluator;
use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionRunner;
use App\Extraction\RecordedExtractionClient;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Extraction\FakeExtractionClient;

function goldPath(): string
{
    return base_path('tests/Fixtures/extraction/gold.json');
}

function recordingPath(): string
{
    return base_path('tests/Fixtures/extraction/recorded-model-output.json');
}

it('has a gold set with at least 40 Norwegian and Swedish answers and the facts the evaluation needs', function () {
    $gold = json_decode((string) file_get_contents(goldPath()), true);
    $languages = array_count_values(array_column($gold['responses'], 'language'));

    expect(($languages['nb'] ?? 0) + ($languages['sv'] ?? 0))->toBeGreaterThanOrEqual(40)
        ->and($languages['nb'])->toBeGreaterThanOrEqual(20)
        ->and($languages['sv'])->toBeGreaterThanOrEqual(20);

    $positives = 0;
    foreach ($gold['responses'] as $response) {
        foreach ($response['expected'] as $expected) {
            expect($expected['is_recommended'])->toBeBool()
                ->and(array_key_exists('list_position', $expected))->toBeTrue();
            $positives += $expected['is_recommended'] ? 1 : 0;
        }
        foreach ($response['citations'] as $citation) {
            expect($citation['source_type'])->toBeIn(['news', 'review', 'forum', 'wiki', 'marketplace', 'social', 'gov', 'other']);
        }
    }
    expect($positives)->toBeGreaterThan(30);
});

it('has a recording that covers every gold answer', function () {
    $gold = json_decode((string) file_get_contents(goldPath()), true);
    $recording = json_decode((string) file_get_contents(recordingPath()), true)['outputs'];

    expect(array_diff(array_column($gold['responses'], 'id'), array_keys($recording)))->toBe([]);
});

it('reports precision and hit rate for is_recommended and list_position against the gold set', function () {
    $report = (new GoldSetEvaluator)->evaluate(goldPath(), new ExtractionRunner(RecordedExtractionClient::fromFile(recordingPath())));

    $recommended = $report->model['recommended'];
    $position = $report->model['position'];

    expect($report->responses)->toBe(80)
        ->and($report->responsesByLanguage)->toBe(['nb' => 39, 'nn' => 13, 'sv' => 28])
        // The recording has one false positive and two misses for is_recommended, and the failed answer.
        ->and([$recommended->truePositives, $recommended->predicted, $recommended->expected])->toBe([68, 69, 70])
        ->and(round($recommended->precision(), 3))->toBe(0.986)
        ->and(round($recommended->hitRate(), 3))->toBe(0.971)
        // One wrong and one missed position, and the failed answer.
        ->and([$position->truePositives, $position->predicted, $position->expected])->toBe([47, 49, 50])
        ->and(round($position->precision(), 3))->toBe(0.959)
        ->and(round($position->hitRate(), 3))->toBe(0.94)
        // The stored list position is the deterministic one, so it is exact.
        ->and($report->assembled['position']->precision())->toBe(1.0)
        ->and($report->assembled['position']->hitRate())->toBe(1.0)
        ->and($report->sourceTypes)->toBe(['correct' => 15, 'total' => 16])
        ->and($report->retried)->toBe(3)
        ->and($report->failedIds)->toBe(['nn-09'])
        ->and($report->inputTokens)->toBeGreaterThan(0)
        ->and(bccomp($report->costUsd, '0', 8))->toBe(1);
});

it('sends every gold answer to the provider once, in batches, with the retry only for bad results', function () {
    config(['ekko.anthropic.extraction.batch_size' => 30]);
    $recorded = json_decode((string) file_get_contents(recordingPath()), true)['outputs'];
    $client = new FakeExtractionClient(fn (string $id): array => $recorded[$id]);

    (new GoldSetEvaluator)->evaluate(goldPath(), new ExtractionRunner($client));

    expect(array_map('count', $client->batches))->toBe([30, 30, 20])
        ->and($client->retries)->toBe(['nb-17', 'nn-09', 'sv-07']);
});

it('prints the report from the artisan command, offline with a recording', function () {
    expect(Artisan::call('ekko:extraction:eval', ['--recorded' => recordingPath()]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('80')->toContain('is_recommended')->toContain('list_position')
        ->toContain('98.6 %')->toContain('97.1 %')->toContain('95.9 %')->toContain('94.0 %')->toContain('100.0 %')
        ->toContain('source_type');
});

it('records the model output of a run so that it can be replayed', function () {
    $recorded = json_decode((string) file_get_contents(recordingPath()), true)['outputs'];
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => $recorded[$id]));
    $copy = tempnam(sys_get_temp_dir(), 'ekko-rec');

    $this->artisan('ekko:extraction:eval', ['--record' => $copy])->assertSuccessful();

    $replayed = (new GoldSetEvaluator)->evaluate(goldPath(), new ExtractionRunner(RecordedExtractionClient::fromFile($copy)));
    unlink($copy);
    expect([$replayed->model['recommended']->truePositives, $replayed->model['recommended']->predicted])->toBe([68, 69])
        ->and($replayed->failedIds)->toBe(['nn-09']);
});

it('tells the owner what is missing instead of failing with a stack trace', function () {
    config(['services.anthropic.key' => null]);

    $this->artisan('ekko:extraction:eval')
        ->expectsOutputToContain('ANTHROPIC_API_KEY')
        ->assertFailed();

    $this->artisan('ekko:extraction:eval', ['--recorded' => '/nonexistent/file.json'])->assertFailed();
});
