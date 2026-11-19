<?php

// T43, docs/spec.md 11.3 and ADR 0034: the same action is recognised at the next generation by its fingerprint
// and never duplicated; actions without support for two generations expire; fact_correction ranks first.

use App\ActionEngine\ActionGenerator;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\ActionGeneration;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Tests\Support\Actions\FakeActionSource;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->source = new FakeActionSource;
    $this->source->evaluated = [$this->w->market->id];
});

function runGeneration(MetricsWorld $w, FakeActionSource ...$sources): mixed
{
    return (new ActionGenerator(array_values($sources)))->generate($w->brand->fresh(), CarbonImmutable::now());
}

function nextWeek(): void
{
    test()->travel(7)->days();
}

test('the same finding is recognised at the next generation and updated, not duplicated', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'bransjeside.no', perMonth: 4, share: 0.5)];
    $first = runGeneration($this->w, $s);
    $action = Action::query()->sole();
    expect($first->created)->toBe(1)->and($first->toWord)->toBe([$action->id])
        ->and($action->impact_score)->toBe('2.00')->and($action->impact_level)->toBe(2);

    nextWeek();
    // Same domain in another case: still the same finding.
    $s->candidates = [$s->candidate($this->w->market->id, 'Bransjeside.no ', perMonth: 40, share: 1)];
    $second = runGeneration($this->w, $s);

    expect(Action::query()->count())->toBe(1)
        ->and($second->created)->toBe(0)->and($second->refreshed)->toBe(1);
    $action->refresh();
    expect($action->impact_score)->toBe('40.00')->and($action->impact_level)->toBe(5)
        ->and($action->last_generated_at->toDateString())->toBe(CarbonImmutable::now()->utc()->toDateString());
});

test('the fingerprint separates type, market and subject', function () {
    $other = Market::factory()->for($this->w->brand)->create();
    $s = $this->source;
    $s->evaluated = [$this->w->market->id, $other->id];
    $s->candidates = [
        $s->candidate($this->w->market->id, 'a.no'),
        $s->candidate($other->id, 'a.no'),
        $s->candidate($this->w->market->id, 'b.no'),
        $s->candidate($this->w->market->id, 'a.no'),
    ];
    $owned = new FakeActionSource(ActionType::OwnedContent);
    $owned->evaluated = [$this->w->market->id];
    $owned->candidates = [$owned->candidate($this->w->market->id, 'a.no')];

    $result = runGeneration($this->w, $s, $owned);

    expect($result->created)->toBe(4)->and(Action::query()->distinct()->count('fingerprint'))->toBe(4);
});

test('a second run in the same week is skipped, and with force refreshes without counting towards expiry', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no')];
    runGeneration($this->w, $s);
    expect(runGeneration($this->w, $s))->toBeNull();

    $s->candidates = [];
    $forced = (new ActionGenerator([$s]))->generate($this->w->brand->fresh(), CarbonImmutable::now(), force: true);

    expect($forced->expired)->toBe(0)
        ->and(Action::query()->sole()->unsupported_generations)->toBe(0)
        ->and(ActionGeneration::query()->count())->toBe(1);
});

test('an action without support for two generations expires, and opens again when the finding returns', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no')];
    runGeneration($this->w, $s);
    $action = Action::query()->sole();

    $s->candidates = [];
    nextWeek();
    runGeneration($this->w, $s);
    expect($action->refresh()->status)->toBe(ActionStatus::New)->and($action->unsupported_generations)->toBe(1);

    nextWeek();
    $result = runGeneration($this->w, $s);
    expect($result->expired)->toBe(1)->and($action->refresh()->status)->toBe(ActionStatus::Expired);

    nextWeek();
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no')];
    $result = runGeneration($this->w, $s);
    expect($result->reopened)->toBe(1)
        ->and($action->refresh()->status)->toBe(ActionStatus::New)
        ->and($action->unsupported_generations)->toBe(0)
        ->and(Action::query()->count())->toBe(1);
});

test('support found again resets the count', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no')];
    runGeneration($this->w, $s);
    $action = Action::query()->sole();

    $s->candidates = [];
    nextWeek();
    runGeneration($this->w, $s);
    nextWeek();
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no')];
    runGeneration($this->w, $s);
    $s->candidates = [];
    nextWeek();
    runGeneration($this->w, $s);

    expect($action->refresh()->status)->toBe(ActionStatus::New)->and($action->unsupported_generations)->toBe(1);
});

test('nothing expires in a scope the source could not judge', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no')];
    runGeneration($this->w, $s);

    $s->candidates = [];
    $s->evaluated = [];
    foreach ([1, 2, 3] as $week) {
        nextWeek();
        runGeneration($this->w, $s);
    }

    expect(Action::query()->sole()->status)->toBe(ActionStatus::New)
        ->and(Action::query()->sole()->unsupported_generations)->toBe(0);
});

test('actions in progress, done, declined or created by a user never expire, and done stays done', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'a.no'), $s->candidate($this->w->market->id, 'b.no'), $s->candidate($this->w->market->id, 'c.no')];
    runGeneration($this->w, $s);
    Action::query()->where('evidence->domain', 'a.no')->update(['status' => 'in_progress']);
    Action::query()->where('evidence->domain', 'b.no')->update(['status' => 'done']);
    Action::query()->where('evidence->domain', 'c.no')->update(['status' => 'declined']);
    $own = Action::factory()->own()->create(['brand_id' => $this->w->brand->id, 'market_id' => $this->w->market->id, 'type' => ActionType::SourceGap]);

    $s->candidates = [];
    foreach ([1, 2, 3] as $week) {
        nextWeek();
        runGeneration($this->w, $s);
    }
    expect(Action::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['in_progress', 'done', 'declined', 'planned']);

    // A done action whose finding returns keeps its status and evidence.
    $done = Action::query()->where('evidence->domain', 'b.no')->sole();
    nextWeek();
    $s->candidates = [$s->candidate($this->w->market->id, 'b.no', perMonth: 99)];
    runGeneration($this->w, $s);
    expect($done->refresh()->status)->toBe(ActionStatus::Done)->and($done->impact_score)->toBe('5.00')
        ->and($own->refresh()->status)->toBe(ActionStatus::Planned);
});

test('fact_correction ranks first within the brand, then expected effect', function () {
    $s = $this->source;
    $s->candidates = [$s->candidate($this->w->market->id, 'big.no', perMonth: 100, share: 1), $s->candidate($this->w->market->id, 'small.no', perMonth: 2, share: 1)];
    $facts = new FakeActionSource(ActionType::FactCorrection);
    $facts->evaluated = [$this->w->market->id];
    $facts->candidates = [$facts->candidate($this->w->market->id, 'founded', perMonth: 1, share: 0.1)];
    runGeneration($this->w, $s, $facts);

    $ranked = Action::query()->open()->ranked()->get();

    expect($ranked->map(fn (Action $a) => $a->evidence['domain'])->all())->toBe(['founded', 'big.no', 'small.no'])
        ->and($ranked[0]->impact_level)->toBe(1)
        ->and($ranked[0]->title)->toBe('Rett feil opplysning: founded');
});

test('a candidate of another type than its source is ignored', function () {
    $s = $this->source;
    $s->candidates = [(new FakeActionSource(ActionType::CrawlerAccess))->candidate(null, 'oai_searchbot')];

    expect(runGeneration($this->w, $s)->created)->toBe(0)->and(Action::query()->count())->toBe(0);
});
