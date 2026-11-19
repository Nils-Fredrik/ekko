<?php

// The evaluation of the fact check against the gold set (docs/spec.md 11.6 and 18, T48). Runs against a recording of
// synthetic model output, with no network and no key. The real model is evaluated by the owner with
// `php artisan ekko:factcheck:eval` (ADR 0036).

use App\FactCheck\Evaluation\FactCheckEvaluator;
use App\FactCheck\Judge\FactJudge;
use App\FactCheck\Judge\RecordedFactJudge;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FactCheck\FakeFactJudge;

function factGoldPath(): string
{
    return base_path('database/evals/factcheck-gold.json');
}

function factRecordingPath(): string
{
    return base_path('tests/Fixtures/factcheck/recorded-model-output.json');
}

it('has a gold set with at least 30 expected claims in Norwegian and Swedish, supported and contradicted', function () {
    $gold = json_decode((string) file_get_contents(factGoldPath()), true);
    $claims = collect($gold['cases'])->flatMap(fn (array $case) => collect($case['expected'])->map(fn (array $row) => $row + ['language' => $case['language']]));

    expect($claims->count())->toBeGreaterThanOrEqual(30)
        ->and($claims->where('language', 'nb')->count())->toBeGreaterThanOrEqual(15)
        ->and($claims->where('language', 'sv')->count())->toBeGreaterThanOrEqual(15)
        ->and($claims->where('verdict', 'supported')->count())->toBeGreaterThanOrEqual(10)
        ->and($claims->where('verdict', 'contradicted')->count())->toBeGreaterThanOrEqual(10)
        ->and($claims->pluck('verdict')->unique()->sort()->values()->all())->toBe(['contradicted', 'supported']);

    $ids = [];
    foreach ($gold['cases'] as $case) {
        expect($ids)->not->toContain($case['id']);
        $ids[] = $case['id'];
        $factIds = array_column($case['facts'], 'id');
        foreach ($case['expected'] as $row) {
            // The fasit refers to a fact of the case and quotes the answer, as the model is asked to.
            expect($factIds)->toContain($row['fact_id'])->and($case['text'])->toContain($row['quote']);
        }
    }
    // Some answers say nothing about a fact: they measure false claims.
    expect(collect($gold['cases'])->filter(fn (array $case) => $case['expected'] === [])->count())->toBeGreaterThanOrEqual(3);
});

it('has a recording that covers every gold case', function () {
    $gold = json_decode((string) file_get_contents(factGoldPath()), true);
    $recording = json_decode((string) file_get_contents(factRecordingPath()), true)['outputs'];

    expect(array_diff(array_column($gold['cases'], 'id'), array_keys($recording)))->toBe([]);
});

it('reports accuracy, precision and hit rate per verdict against the gold set', function () {
    $report = (new FactCheckEvaluator)->evaluate(factGoldPath(), RecordedFactJudge::fromFile(factRecordingPath()));

    $supported = $report->verdicts['supported'];
    $contradicted = $report->verdicts['contradicted'];

    expect($report->cases)->toBe(37)->and($report->expectedClaims)->toBe(43)
        ->and($report->casesByLanguage)->toBe(['nb' => 18, 'sv' => 19])
        // The recording flips two contradicted claims to supported, misses one supported and one contradicted claim,
        // reports a quote that is not in the answer (dropped), and adds one claim on a fact without a fasit.
        ->and([$supported->truePositives, $supported->predicted, $supported->expected])->toBe([26, 29, 27])
        ->and([$contradicted->truePositives, $contradicted->predicted, $contradicted->expected])->toBe([12, 12, 16])
        ->and(round($supported->precision(), 3))->toBe(0.897)
        ->and(round($contradicted->hitRate(), 3))->toBe(0.75)
        ->and($report->correct)->toBe(38)->and(round($report->accuracy(), 3))->toBe(0.884)
        ->and($report->accuracyByLanguage)->toBe(['nb' => ['correct' => 19, 'total' => 21], 'sv' => ['correct' => 19, 'total' => 22]])
        ->and($report->unexpectedClaims)->toBe(1)
        ->and($report->failedIds)->toBe([])
        ->and($report->inputTokens)->toBeGreaterThan(0)->and(bccomp($report->costUsd, '0', 8))->toBe(1);
});

it('sends every gold case once with its facts, and counts a bad reply as a failed case', function () {
    $recorded = json_decode((string) file_get_contents(factRecordingPath()), true)['outputs'];
    $recorded['sv-05']['text'] = 'not json';
    $judge = new FakeFactJudge(fn (string $id): array => $recorded[$id]);

    $report = (new FactCheckEvaluator)->evaluate(factGoldPath(), $judge);

    $user = collect($judge->requests)->keyBy('customId')['nb-01']->user;
    expect($judge->requests)->toHaveCount(37)
        ->and($report->failedIds)->toBe(['sv-05'])
        ->and($user)->toContain('brand_name: "Fjordkaffe"')->toContain('{"id":1,"text":"Fjordkaffe ble grunnlagt i 2009."}');
});

it('prints the report from the artisan command, offline with a recording', function () {
    expect(Artisan::call('ekko:factcheck:eval', ['--recorded' => factRecordingPath()]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('37 svar og 43 forventede påstander')->toContain('supported')->toContain('contradicted')
        ->toContain('Treffsikkerhet: 38 av 43')->toContain('88.4 %')->toContain('nb 90.5 %')->toContain('sv 86.4 %')
        ->toContain('Påstander på fakta uten fasit: 1');
});

it('records the model output of a run so that it can be replayed', function () {
    $recorded = json_decode((string) file_get_contents(factRecordingPath()), true)['outputs'];
    app()->instance(FactJudge::class, new FakeFactJudge(fn (string $id): array => $recorded[$id]));
    $copy = tempnam(sys_get_temp_dir(), 'ekko-fact');

    $this->artisan('ekko:factcheck:eval', ['--record' => $copy])->assertSuccessful();

    $replayed = (new FactCheckEvaluator)->evaluate(factGoldPath(), RecordedFactJudge::fromFile($copy));
    unlink($copy);
    expect($replayed->correct)->toBe(38);
});

it('tells the owner what is missing instead of failing with a stack trace', function () {
    config(['services.anthropic.key' => null]);

    $this->artisan('ekko:factcheck:eval')->expectsOutputToContain('ANTHROPIC_API_KEY')->assertFailed();
    $this->artisan('ekko:factcheck:eval', ['--recorded' => '/nonexistent/file.json'])->assertFailed();
});
