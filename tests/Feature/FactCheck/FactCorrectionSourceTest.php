<?php

// T48, docs/spec.md 11.3 and 11.6, ADR 0036: a fact that at least two answers contradict becomes a fact_correction
// action, with the numbers read from claims, answers and citations. "Today" is Monday 2026-11-16 in Oslo, so the
// 30-day window is 2026-10-17 to 2026-11-15.

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\ActionTemplates;
use App\ActionEngine\Sources\FactCorrectionSource;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Prompt;
use Carbon\CarbonImmutable;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->market->forceFill(['language' => 'nb', 'country' => 'NO'])->save();
    // Enough answers in the window to judge the brand; the production threshold is 30.
    config(['ekko.actions.min_answers' => 4]);
    $this->fact = BrandFact::factory()->for($this->w->brand)->create(['text' => 'Fjordkaffe ble grunnlagt i 2009.']);
    foreach (range(1, 4) as $i) {
        $this->w->answer($this->w->p2, '2026-11-10 10:00:00', ['brand' => ['pos' => 1]]);
    }
});

/**
 * One answer with a claim on the fact. Citations are [domain, ownership]; the claim's sources are the first
 * $sourceCount of them.
 *
 * @param  list<array{0: string, 1: string}>  $citations
 */
function claimAnswer(MetricsWorld $w, BrandFact $fact, string $verdict, array $citations = [], ?int $sourceCount = null, string $at = '2026-11-10 10:00:00', ?Prompt $prompt = null): Claim
{
    $response = $w->answer($prompt ?? $w->p1, $at, ['brand' => ['pos' => 1], 'citations' => $citations]);
    $ids = Citation::query()->where('response_id', $response->id)->orderBy('id')->pluck('id')->take($sourceCount ?? count($citations))->all();

    return Claim::query()->create([
        'response_id' => $response->id, 'brand_id' => $w->brand->id, 'fact_id' => $fact->id,
        'text' => 'Grunnlagt i 2012', 'quote' => 'grunnlagt i 2012', 'verdict' => $verdict, 'source_citation_ids' => $ids,
    ]);
}

function generateFactActions(MetricsWorld $w): void
{
    (new ActionGenerator([new FactCorrectionSource]))->generate($w->brand->fresh(), CarbonImmutable::now());
}

test('a fact that two answers contradict becomes a brand-level fact_correction with the numbers from the claims', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['kaffeguiden.no', 'third_party']]);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['kaffeguiden.no', 'third_party']], prompt: $w->p2);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['kaffeguiden.no', 'third_party']], prompt: $w->p2);
    claimAnswer($w, $this->fact, Claim::SUPPORTED);

    generateFactActions($w);

    $action = Action::query()->sole();
    expect($action->type)->toBe(ActionType::FactCorrection)
        ->and($action->market_id)->toBeNull()
        ->and($action->status)->toBe(ActionStatus::New)
        ->and($action->evidence['fact_id'])->toBe($this->fact->id)
        ->and($action->evidence['fact'])->toBe('Fjordkaffe ble grunnlagt i 2009.')
        ->and($action->evidence['contradicted_responses'])->toBe(3)
        ->and($action->evidence['supported_responses'])->toBe(1)
        ->and($action->evidence['responses'])->toBe(8)
        ->and($action->evidence['period'])->toEqual(['from' => '2026-10-17', 'to' => '2026-11-15', 'days' => 30])
        ->and($action->evidence['claims'])->toBe(['Grunnlagt i 2012'])
        ->and($action->evidence['sources'])->toEqual([['url' => 'https://kaffeguiden.no/x', 'domain' => 'kaffeguiden.no', 'ownership' => 'third_party', 'responses' => 3]])
        ->and($action->evidence['source_kind'])->toBe('other')
        ->and($action->engine_keys)->toBe([$w->engine->key])
        ->and($action->prompts()->pluck('prompts.id')->sort()->values()->all())->toBe([$w->p1->id, $w->p2->id])
        // 3 answers in 30 days is 3 a month and every one counts: 3 × 1.
        ->and($action->impact_score)->toBe('3.00')
        ->and($action->evidence['text_params'])->toMatchArray([
            'fact' => 'Fjordkaffe ble grunnlagt i 2009', 'prompts' => 2, 'source' => 'https://kaffeguiden.no/x', 'source_kind' => 'other', 'days' => 30,
        ]);

    // The template text: the evidence sentence names the fact and the page, and the step says to ask the publisher.
    expect(ActionTemplates::evidenceSentence($action, 'nb'))->toBe('Svar på 2 prompts oppgir feil opplysning om Fjordkaffe ble grunnlagt i 2009. Kilden er https://kaffeguiden.no/x.')
        ->and(collect($action->steps)->pluck('text')->all())->toBe(['Finn siden svaret bygger på: https://kaffeguiden.no/x.', 'Be utgiveren av siden rette opplysningen.']);
});

test('one contradicting answer is not enough, and the claims of another brand or an inactive fact do not count', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['kaffeguiden.no', 'third_party']]);
    $off = BrandFact::factory()->for($w->brand)->create(['is_active' => false]);
    claimAnswer($w, $off, Claim::CONTRADICTED);
    claimAnswer($w, $off, Claim::CONTRADICTED);
    // Two claims in the same answer are one answer.
    $first = claimAnswer($w, $this->fact, Claim::CONTRADICTED);
    Claim::query()->create([...$first->only(['response_id', 'brand_id', 'fact_id', 'quote', 'verdict']), 'text' => 'Fra 2012']);

    generateFactActions($w);

    // Two answers contradict the active fact: the first and $first. The inactive fact has none that count.
    $action = Action::query()->sole();
    expect($action->evidence['fact_id'])->toBe($this->fact->id)->and($action->evidence['contradicted_responses'])->toBe(2)
        ->and($action->evidence['claims'])->toBe(['Grunnlagt i 2012', 'Fra 2012']);
});

test('answers outside the window, and answers whose run is not extracted, do not count', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, at: '2026-10-16 21:00:00');   // 2026-10-16 23:00 Oslo: before the window
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, at: '2026-11-15 23:30:00');   // 2026-11-16 00:30 Oslo: after the window
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, at: '2026-11-15 22:30:00');   // 23:30 Oslo on the 15th: inside
    expect(Action::query()->count())->toBe(0);

    generateFactActions($w);
    expect(Action::query()->count())->toBe(0);

    claimAnswer($w, $this->fact, Claim::CONTRADICTED, at: '2026-10-16 22:30:00');   // 00:30 Oslo on the 17th: inside
    generateFactActions($w);
    // The week is already counted, so force to see the new claim.
    (new ActionGenerator([new FactCorrectionSource]))->generate($w->brand->fresh(), CarbonImmutable::now(), force: true);

    expect(Action::query()->sole()->evidence['contradicted_responses'])->toBe(2);
});

test('the action says to correct the own page when the source is on the brand domain, and when no source is known', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['fjordkaffe.no', 'own'], ['kaffeguiden.no', 'third_party']], sourceCount: 1);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['fjordkaffe.no', 'own']]);
    generateFactActions($w);

    $action = Action::query()->sole();
    expect($action->evidence['source_kind'])->toBe('own')
        ->and($action->evidence['sources'][0]['ownership'])->toBe('own')
        ->and(collect($action->steps)->pluck('text')->last())->toBe('Rett deres egen side, slik at opplysningen stemmer.');

    // Without a named source (the answers do not show where the claim comes from) the action says so.
    $other = BrandFact::factory()->for($w->brand)->create(['text' => 'Fjordkaffe har tre kaffebarer.']);
    claimAnswer($w, $other, Claim::CONTRADICTED, [['kaffeguiden.no', 'third_party']], sourceCount: 0);
    claimAnswer($w, $other, Claim::CONTRADICTED);
    (new ActionGenerator([new FactCorrectionSource]))->generate($w->brand->fresh(), CarbonImmutable::now(), force: true);

    $unknown = Action::query()->where('evidence->fact_id', $other->id)->sole();
    expect($unknown->evidence['source_kind'])->toBe('unknown')->and($unknown->evidence['sources'])->toBe([])
        ->and(ActionTemplates::evidenceSentence($unknown, 'nb'))->toContain('Kilden er ikke oppgitt i svarene.')
        ->and(ActionTemplates::evidenceSentence($unknown, 'sv'))->toContain('Källan är inte angiven i svaren.')
        ->and(ActionTemplates::evidenceSentence($unknown, 'en'))->toContain('The source is not given in the answers.')
        ->and(collect($unknown->steps)->pluck('text')->all())->toBe([
            'Finn hvilken side svarene bygger på, for eksempel ved å søke etter påstanden i svarene.',
            'Rett siden hvis den er deres egen, ellers be utgiveren rette den.',
        ]);
});

test('the most cited page is the source, ties broken by address', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['b-side.no', 'third_party']]);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['a-side.no', 'third_party'], ['c-side.no', 'third_party']]);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, [['c-side.no', 'third_party']]);
    generateFactActions($w);

    $sources = Action::query()->sole()->evidence['sources'];
    expect(array_column($sources, 'domain'))->toBe(['c-side.no', 'a-side.no', 'b-side.no'])
        ->and(array_column($sources, 'responses'))->toBe([2, 1, 1]);
});

test('a fact_correction ranks first within its brand and expires when the answers stop contradicting the fact', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED);
    $gap = Action::factory()->create(['brand_id' => $w->brand->id, 'type' => ActionType::SourceGap, 'impact_level' => 5, 'impact_score' => 90]);
    generateFactActions($w);

    $fact = Action::query()->where('type', 'fact_correction')->sole();
    expect(Action::query()->where('brand_id', $w->brand->id)->open()->ranked()->pluck('id')->all())->toBe([$fact->id, $gap->id]);

    // The claims are gone (the fact text was changed): two generations without support expire the action.
    Claim::query()->delete();
    $this->travel(7)->days();
    generateFactActions($w);
    expect($fact->refresh()->status)->toBe(ActionStatus::New)->and($fact->unsupported_generations)->toBe(1);
    $this->travel(7)->days();
    generateFactActions($w);
    expect($fact->refresh()->status)->toBe(ActionStatus::Expired);

    // The contradiction returns: the same action opens again, it is not duplicated.
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, at: '2026-11-25 10:00:00');
    claimAnswer($w, $this->fact, Claim::CONTRADICTED, at: '2026-11-25 11:00:00');
    $this->travel(7)->days();
    generateFactActions($w);
    expect($fact->refresh()->status)->toBe(ActionStatus::New)
        ->and(Action::query()->where('type', 'fact_correction')->count())->toBe(1);
});

test('a brand with too few answers in the window is not judged, so nothing expires', function () {
    $w = $this->w;
    claimAnswer($w, $this->fact, Claim::CONTRADICTED);
    claimAnswer($w, $this->fact, Claim::CONTRADICTED);
    generateFactActions($w);
    $action = Action::query()->sole();

    config(['ekko.actions.min_answers' => 100]);
    foreach (range(1, 3) as $_) {
        $this->travel(7)->days();
        generateFactActions($w);
    }

    expect($action->refresh()->status)->toBe(ActionStatus::New)->and($action->unsupported_generations)->toBe(0);
});

test('the source is registered in ekko.actions.sources', function () {
    expect(config('ekko.actions.sources'))->toContain(FactCorrectionSource::class)
        ->and(app(FactCorrectionSource::class)->type())->toBe(ActionType::FactCorrection);
});
