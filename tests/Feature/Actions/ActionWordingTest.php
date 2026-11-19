<?php

// T43, docs/spec.md 11.3 and ADR 0034: Claude Sonnet 5.5 words new actions from the evidence (a fake here, no
// paid call). The figures stay in the evidence: a text with a number that is not part of a name is refused and
// the template text stays. Each known call is booked as usage and settled on the Anthropic account.

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\ActionTextGuard;
use App\ActionEngine\Writing\ActionTextRequest;
use App\ActionEngine\Writing\ActionWriter;
use App\ActionEngine\Writing\ActionWriterFailed;
use App\ActionEngine\Writing\ActionWriterResult;
use App\AnthropicBudget\Account;
use App\Enums\ActionStatus;
use App\Jobs\GenerateTenantActions;
use App\Models\Action;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Actions\FakeActionSource;
use Tests\Support\Actions\FakeActionWriter;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->market->forceFill(['language' => 'sv-SE', 'country' => 'SE'])->save();
    $this->source = new FakeActionSource;
    $this->source->evaluated = [$this->w->market->id];
    $this->source->candidates = [$this->source->candidate($this->w->market->id, 'branschsajt.se')];
    app()->instance(ActionGenerator::class, new ActionGenerator([$this->source]));
    config(['services.anthropic.key' => 'synthetic-key']);
    $this->writer = new FakeActionWriter(FakeActionWriter::json(
        'Bli nämnda på branschsajt.se',
        'Branschsajt.se citeras när era konkurrenter nämns. Synas där.',
        ['1. Läs de citerade sidorna.', 'Kontakta redaktionen.'],
    ));
    app()->instance(ActionWriter::class, $this->writer);
});

afterEach(function () {
    // The independent budget connection commits outside the test transaction (ADR 0023).
    $budget = Account::independent()->db();
    $budget->statement("SET lock_timeout = '2s'");
    $budget->table('anthropic_budget_holds')->delete();
    $budget->table('anthropic_budget_cycles')->delete();
    $budget->table('anthropic_credit_spend')->where('entry', 'like', 'actions:%')->delete();
    DB::purge('anthropic_budget');
});

function generateTenant(MetricsWorld $w): void
{
    dispatch_sync(new GenerateTenantActions($w->tenant->id));
}

test('a new action is worded by the model in the market language and the call is booked', function () {
    generateTenant($this->w);

    $action = Action::query()->sole();
    expect($action->text_source)->toBe('model')
        ->and($action->title)->toBe('Bli nämnda på branschsajt.se')
        ->and($action->steps)->toEqual([['text' => 'Läs de citerade sidorna.', 'done' => false], ['text' => 'Kontakta redaktionen.', 'done' => false]])
        ->and($action->locale)->toBe('sv-SE')
        ->and($action->status)->toBe(ActionStatus::New);

    $request = $this->writer->requests[0];
    expect($request->system)->toContain('Write in Swedish')->and($request->user)->toContain('branschsajt.se')
        ->and($request->user)->not->toContain('text_params');

    $usage = UsageEvent::query()->sole();
    // 1200 input and 300 output tokens at 2 and 10 USD per million.
    expect($usage->kind)->toBe('actions')->and($usage->billed_to)->toBe('platform')
        ->and($usage->cost_usd)->toBe('0.00540000')->and($usage->anthropic_operation)->toStartWith('actions:')
        ->and(DB::table('anthropic_credit_spend')->where('entry', $usage->anthropic_operation)->value('cost_usd'))->toBe('0.00540000');
});

test('a recognised action is not worded again', function () {
    generateTenant($this->w);
    $this->travel(7)->days();
    generateTenant($this->w);

    expect($this->writer->requests)->toHaveCount(1)->and(UsageEvent::query()->count())->toBe(1);
});

test('a text with a figure is refused, the template stays and the call is still booked', function () {
    $this->writer->reply = FakeActionWriter::json('Branschsajt.se citerades i 14 svar', 'Text.', ['Steg.']);

    generateTenant($this->w);

    $action = Action::query()->sole();
    expect($action->text_source)->toBe('template')
        ->and($action->title)->toBe('Bli omnämnda på branschsajt.se')
        ->and(UsageEvent::query()->count())->toBe(1);
});

test('the guard accepts digits that are part of a name and refuses invented ones', function () {
    $guard = new ActionTextGuard;
    $names = ['24sevenoffice.no', 'Shopify 2.0'];

    expect($guard->parse(FakeActionWriter::json('Bli sett på 24sevenoffice.no', 'Om Shopify 2.0.', ['Gjør det.']), $names))->not->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Øk med 30 %', 'x', ['y']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'Siden 2019.', ['y']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'x', ['Vent 2 uker.']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json(str_repeat('x', 121), 'x', ['y']), $names))->toBeNull()
        ->and($guard->parse('{"title": "x"}', $names))->toBeNull()
        ->and($guard->parse('not json', $names))->toBeNull();
});

test('without an API key no call is made and the template text stays', function () {
    config(['services.anthropic.key' => '']);

    generateTenant($this->w);

    expect($this->writer->requests)->toBe([])->and(Action::query()->sole()->text_source)->toBe('template')
        ->and(UsageEvent::query()->count())->toBe(0);
});

test('a refused or truncated reply is booked and leaves the template', function () {
    $this->writer->reply = null;

    generateTenant($this->w);

    expect(Action::query()->sole()->text_source)->toBe('template')->and(UsageEvent::query()->count())->toBe(1);
});

test('at most max_formulations calls per run; the rest are worded at the next generation', function () {
    config(['ekko.actions.max_formulations' => 1]);
    $this->source->candidates[] = $this->source->candidate($this->w->market->id, 'annan.se');

    generateTenant($this->w);
    expect(Action::query()->where('text_source', 'model')->count())->toBe(1);

    $this->travel(7)->days();
    generateTenant($this->w);
    expect(Action::query()->where('text_source', 'model')->count())->toBe(2)->and($this->writer->requests)->toHaveCount(2);
});

test('with a configured pool the call reserves first; in reduced mode no call is made', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);
    Account::independent()->markExhausted();

    generateTenant($this->w);

    expect($this->writer->requests)->toBe([])->and(Action::query()->sole()->text_source)->toBe('template');
});

test('a known provider error releases the reservation; an unknown outcome keeps it', function (bool $known) {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);
    $this->writer->failure = new ActionWriterFailed('x', known: $known);

    generateTenant($this->w);

    expect(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe($known ? 0 : 1)
        ->and(UsageEvent::query()->count())->toBe(0)
        ->and(Action::query()->sole()->text_source)->toBe('template');
})->with(['known' => true, 'unknown' => false]);

test('a credit balance error stops further Anthropic calls for the period', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);
    $this->writer->failure = new ActionWriterFailed('x', known: true, creditExhausted: true);

    generateTenant($this->w);

    $account = app(Account::class);
    expect($account->exhausted($account->cycle()))->toBeTrue();
});

test('the user acting on an action before the reply keeps the user change', function () {
    $writer = new class implements ActionWriter
    {
        public function write(ActionTextRequest $request): ActionWriterResult
        {
            Action::query()->update(['status' => 'planned']);

            return new ActionWriterResult(FakeActionWriter::json('Ny tittel', 'x', ['y']), 1, 1);
        }
    };
    app()->instance(ActionWriter::class, $writer);

    generateTenant($this->w);

    expect(Action::query()->sole()->text_source)->toBe('template');
});
