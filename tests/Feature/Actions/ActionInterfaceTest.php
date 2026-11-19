<?php

use App\ActionEngine\ActionTemplates;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\AggDaily;
use App\Models\Market;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\User;
use App\Reports\ReportActions;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->world = MetricsWorld::create('UTC');
    $this->owner = $this->world->tenant->owner;
    $this->owner->switchTeam($this->world->tenant);
    $this->action = Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'market_id' => $this->world->market->id,
        'title' => 'Bli omtalt på bransjeside.no',
        'evidence' => ['text_params' => ['domain' => 'bransjeside.no', 'gap_responses' => 14, 'days' => 30, 'competitor' => 'Nordlys', 'competitor_answers' => 11], 'domain' => 'bransjeside.no'],
        'competitor_evidence' => [['name' => 'Nordlys', 'answers' => 11, 'of' => 14]],
        'steps' => [['text' => 'Se sidene', 'done' => false]],
    ]);
});

test('the list filters by status and the detail shows the evidence', function () {
    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'status' => ActionStatus::Done,
        'title' => 'Ferdig tiltak',
        'completed_at' => '2026-11-01',
    ]);

    $this->actingAs($this->owner)
        ->get(route('brands.actions.index', ['brand' => $this->world->brand, 'status' => 'new']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Actions')
            ->has('actions', 1)
            ->where('actions.0.title', 'Bli omtalt på bransjeside.no'));

    $this->actingAs($this->owner)
        ->get(route('brands.actions.show', [$this->world->brand, $this->action]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('action.evidence', fn (string $sentence) => str_contains($sentence, 'bransjeside.no') && str_contains($sentence, '14'))
            ->where('action.competitors.0', fn (string $line) => str_contains($line, 'Nordlys')));
});

test('the detail shows the figures the sources store', function () {
    $this->action->update([
        'evidence' => [
            'text_params' => ['domain' => 'bransjeside.no', 'gap_responses' => 14, 'days' => 30],
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'days' => 30],
            'domain' => 'bransjeside.no',
            'gap_responses' => 14,
            'market_competitor_only_responses' => 40,
            'prompts_without_own_citation' => 3,
            'competitor_only_responses' => 6,
            'visibility' => ['value' => 0.25, 'n' => 80, 'low_sample' => false],
            'max_visibility' => 0.5,
            'share' => ['value' => null, 'n' => 0, 'low_sample' => true],
            'other_bot_hits' => 12,
            'hits' => 0,
            'answers' => 9,
            'answers_without_brand' => 4,
            'threshold_percent' => 10,
        ],
    ]);

    $this->actingAs($this->owner)
        ->get(route('brands.actions.show', [$this->world->brand, $this->action]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('action.figures', function (mixed $figures): bool {
            $byLabel = collect($figures)->pluck('value', 'label');

            return $byLabel['Periode'] === '2026-10-01 – 2026-10-31'
                && $byLabel['Domene'] === 'bransjeside.no'
                && $byLabel['Svar med gap'] === '14'
                && $byLabel['Svar med konkurrent og uten merkevaren'] === '40'
                && $byLabel['Prompter uten egen sitering'] === '3'
                && $byLabel['Svar med bare konkurrenter'] === '6'
                && $byLabel['Synlighet'] === '25,0 %'
                && $byLabel['Grense for synlighet'] === '50,0 %'
                && $byLabel['Andel'] === '–'
                && $byLabel['Treff fra andre roboter'] === '12'
                && $byLabel['Treff'] === '0'
                && $byLabel['Svar som nevner navnet'] === '9'
                && $byLabel['Svar uten merkevaren'] === '4'
                && $byLabel['Terskel'] === '10,0 %';
        }));
});

test('status and steps can be changed from the interface, and a reader cannot', function () {
    $reader = User::factory()->create();
    $this->world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($this->world->tenant);

    $this->actingAs($reader)
        ->patch(route('brands.actions.update', [$this->world->brand, $this->action]), ['status' => 'planned'])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->patch(route('brands.actions.update', [$this->world->brand, $this->action]), ['status' => 'in_progress'])
        ->assertRedirect();
    expect($this->action->fresh()->status)->toBe(ActionStatus::InProgress)
        ->and($this->action->fresh()->started_at)->not->toBeNull();

    $this->actingAs($this->owner)
        ->patch(route('brands.actions.steps', [$this->world->brand, $this->action, 0]), ['done' => true])
        ->assertRedirect();
    expect($this->action->fresh()->steps[0]['done'])->toBeTrue();
});

test('a user can add their own action', function () {
    $this->actingAs($this->owner)
        ->post(route('brands.actions.store', $this->world->brand), [
            'title' => 'Skriv en side om kaffe',
            'description' => 'Egen tekst',
            'steps' => "Finn hullene\nSkriv siden",
            'prompt_ids' => [$this->world->p1->id],
        ])
        ->assertRedirect();

    $own = Action::query()->where('title', 'Skriv en side om kaffe')->first();
    expect($own)->not->toBeNull()
        ->and($own->status)->toBe(ActionStatus::Planned)
        ->and($own->created_by)->toBe(Action::CREATED_BY_USER)
        ->and($own->steps)->toHaveCount(2)
        ->and($own->prompts)->toHaveCount(1);
});

test('a typed action created by the user does not show unresolved evidence placeholders', function () {
    $this->actingAs($this->owner)
        ->post(route('brands.actions.store', $this->world->brand), [
            'title' => 'Eget med type',
            'type' => ActionType::SourceGap->value,
        ])
        ->assertRedirect();

    $own = Action::query()->where('title', 'Eget med type')->first();
    expect($own)->not->toBeNull()
        ->and(ActionTemplates::evidenceSentence($own, 'nb'))->toBeNull();

    $this->get(route('brands.actions.show', [$this->world->brand, $own]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('action.evidence', ''));
});

test('the report section keeps the top open actions and the visibility change of those completed in the period', function () {
    foreach (range(1, 5) as $level) {
        Action::factory()->create([
            'brand_id' => $this->world->brand->id,
            'title' => "Åpent {$level}",
            'impact_level' => $level,
            'impact_score' => $level,
            'status' => ActionStatus::New,
        ]);
    }
    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'title' => 'Fullført i perioden',
        'status' => ActionStatus::Done,
        'completed_at' => '2026-10-15 12:00:00',
    ]);
    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'title' => 'Fullført utenfor',
        'status' => ActionStatus::Done,
        'completed_at' => '2026-09-01 12:00:00',
    ]);

    $schedule = ReportSchedule::factory()->create([
        'brand_id' => $this->world->brand->id,
        'locale' => 'nb',
    ]);
    app()->setLocale('nb');
    $html = view('reports.pdf', app(ReportPublisher::class)->viewData(
        $this->world->tenant,
        $schedule,
        '2026-10-01',
        '2026-10-31',
        null,
    ))->render();

    expect($html)->toContain('Tiltak')->toContain('Åpent 5')->toContain('Fullført i perioden')
        ->not->toContain('Åpent 1')
        ->not->toContain('Fullført utenfor');

    $section = app(ReportActions::class)->section($this->world->brand->id, '2026-10-01', '2026-10-31');
    $report = Report::factory()->create([
        'report_schedule_id' => $schedule->id,
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'action_section' => $section,
    ]);
    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'title' => 'Etter rapporten',
        'impact_level' => 5,
        'impact_score' => 100,
        'status' => ActionStatus::New,
    ]);
    $this->actingAs($this->owner)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->has('actions.open', 5)
            ->where('actions.completed.0.title', 'Fullført i perioden')
            ->where('actions.open', fn (mixed $open): bool => collect($open)->pluck('title')->doesntContain('Etter rapporten')));

    $unstored = Report::factory()->create([
        'report_schedule_id' => $schedule->id,
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
    ]);
    $this->get(route('reports.show', $unstored))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('actions.open', 0)->has('actions.completed', 0));
});

test('the visibility change uses the completion day, the same number of days before, and the action market', function () {
    $other = Market::factory()->for($this->world->brand)->create();
    $row = function (string $date, int $mentioned, int $marketId): void {
        AggDaily::factory()->create([
            'market_id' => $marketId,
            'brand_id' => $this->world->brand->id,
            'engine_key' => $this->world->engine->key,
            'prompt_id' => $this->world->p1->id,
            'date' => $date,
            'responses' => 10,
            'brand_mentioned' => $mentioned,
        ]);
    };
    $row('2026-10-15', 8, $this->world->market->id);
    $row('2026-10-14', 5, $this->world->market->id);
    $row('2026-10-14', 0, $other->id);
    $row('2026-10-13', 0, $this->world->market->id);
    AggDaily::query()->where('date', '2026-10-13')->update(['responses' => 100]);

    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'market_id' => $this->world->market->id,
        'title' => 'Målt',
        'status' => ActionStatus::Done,
        'completed_at' => '2026-10-15 12:00:00',
    ]);
    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'market_id' => $this->world->market->id,
        'title' => 'Uten data',
        'status' => ActionStatus::Done,
        'completed_at' => '2026-01-05 12:00:00',
    ]);

    app()->setLocale('nb');
    $measured = collect(app(ReportActions::class)->section($this->world->brand->id, '2026-10-01', '2026-10-15')['completed'])
        ->firstWhere('title', 'Målt');
    $missing = collect(app(ReportActions::class)->section($this->world->brand->id, '2026-01-01', '2026-01-05')['completed'])
        ->firstWhere('title', 'Uten data');
    app()->setLocale('en');
    $english = collect(app(ReportActions::class)->section($this->world->brand->id, '2026-10-01', '2026-10-15')['completed'])
        ->firstWhere('title', 'Målt');

    expect($measured['change'] ?? null)->toBe('+30,0 pp')
        ->and($english['change'] ?? null)->toBe('+30.0 pp')
        ->and($missing['change'] ?? null)->toBe('–');
});

test('a completion just after local midnight belongs to that local day', function () {
    DB::table('teams')->where('id', $this->world->tenant->id)->update(['timezone' => 'Europe/Oslo']);

    Action::factory()->create([
        'brand_id' => $this->world->brand->id,
        'title' => 'Fullført etter midnatt',
        'status' => ActionStatus::Done,
        // 00:30 in Europe/Oslo on 1 November is 23:30 UTC on 31 October.
        'completed_at' => '2026-10-31 23:30:00',
    ]);

    $october = app(ReportActions::class)->section($this->world->brand->id, '2026-10-01', '2026-10-31');
    $november = app(ReportActions::class)->section($this->world->brand->id, '2026-11-01', '2026-11-30');

    expect(collect($october['completed'])->pluck('title'))->not->toContain('Fullført etter midnatt')
        ->and(collect($november['completed'])->pluck('title'))->toContain('Fullført etter midnatt');
});

test('an action from another brand is not shown', function () {
    $other = app(TenantContext::class)->run(
        Team::factory()->tenant()->create()->id,
        fn () => Action::factory()->create(),
    );

    $this->actingAs($this->owner)
        ->get(route('brands.actions.show', [$this->world->brand, $other]))
        ->assertNotFound();
});
