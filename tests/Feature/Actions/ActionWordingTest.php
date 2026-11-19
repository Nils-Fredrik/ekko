<?php

// T43, docs/spec.md 11.3 and ADR 0034: Claude Sonnet 5.5 words new actions from the evidence (a fake here, no
// paid call). The figures stay in the evidence: a text with a number that is not part of a name is refused and
// the template text stays. Each known call is booked as usage and settled on the Anthropic account.

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\ActionPromptBuilder;
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
use Illuminate\Support\Facades\Log;
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

test('action usage keeps its pitch classification when conversion happens during the call', function () {
    $this->w->tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
    $writer = Mockery::mock(ActionWriter::class);
    $writer->shouldReceive('write')->once()->andReturnUsing(function () {
        $this->w->tenant->forceFill(['kind' => 'customer', 'pitch_converted_at' => now()])->save();

        return new ActionWriterResult(FakeActionWriter::json('Tittel', 'Beskrivelse', ['Steg en', 'Steg to']), 1200, 300);
    });
    app()->instance(ActionWriter::class, $writer);

    generateTenant($this->w);

    expect(UsageEvent::query()->sole()->getAttribute('project_kind'))->toBe('pitch');
});

test('each call writes one structured log line with internal IDs and no text (T43-F1)', function () {
    Log::spy();

    generateTenant($this->w);

    $action = Action::query()->sole();
    $usage = UsageEvent::query()->sole();
    Log::shouldHaveReceived('info')->with('Action wording call.', Mockery::on(fn (array $context): bool => $context === [
        'tenant' => $this->w->tenant->id,
        'action' => $action->id,
        'operation' => $usage->anthropic_operation,
        'outcome' => 'worded',
        'usage_event' => $usage->id,
        'input_tokens' => 1200,
        'output_tokens' => 300,
        'cost_usd' => '0.00540000',
    ]))->once();
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

test('the guard accepts digits only inside a name written in full and refuses invented ones', function () {
    $guard = new ActionTextGuard;
    $names = ['24sevenoffice.no', 'Shopify 2.0', 'Top 20 coffee brands'];

    expect($guard->parse(FakeActionWriter::json('Bli sett på 24sevenoffice.no', 'Om Shopify 2.0.', ['Svar på «Top 20 coffee brands».', 'Følg opp.']), $names))->not->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Øk med 30 %', 'x', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Øk med 20 %', 'x', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'Nå 20 kunder.', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'Shopify 2', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Få 5x flere svar', 'x', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'Før Q4.', ['Innen 14d.', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'Siden 2019.', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'x', ['Vent 2 uker.', 'z']), $names))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json(str_repeat('x', 121), 'x', ['y', 'z']), $names))->toBeNull()
        ->and($guard->parse('{"title": "x"}', $names))->toBeNull()
        ->and($guard->parse('not json', $names))->toBeNull();
});

test('the guard asks for two to five steps like the prompt, and every template has that many (T43-F2)', function () {
    $guard = new ActionTextGuard;

    expect($guard->parse(FakeActionWriter::json('Tittel', 'x', ['y']), []))->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'x', ['a', 'b']), []))->not->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'x', ['a', 'b', 'c', 'd', 'e']), []))->not->toBeNull()
        ->and($guard->parse(FakeActionWriter::json('Tittel', 'x', ['a', 'b', 'c', 'd', 'e', 'f']), []))->toBeNull();

    foreach (['nb', 'sv', 'en'] as $locale) {
        $templates = trans('action_items.templates', [], $locale);
        expect($templates)->toBeArray()->not->toBeEmpty();
        foreach ($templates as $type => $template) {
            expect(count($template['steps']))->toBeGreaterThanOrEqual(ActionPromptBuilder::MIN_STEPS, "{$locale}.{$type}")
                ->toBeLessThanOrEqual(ActionPromptBuilder::MAX_STEPS, "{$locale}.{$type}");
        }
    }
});

test('a market in a language without templates gets the template in English and the model writes in the market language', function () {
    $this->w->market->forceFill(['language' => 'da', 'country' => 'DK'])->save();

    generateTenant($this->w);

    expect($this->writer->requests[0]->system)->toContain('Write in Danish')
        ->and(Action::query()->sole()->locale)->toBe('da');
});

test('no model call starts after the deadline; the action keeps the template', function () {
    dispatch_sync(new GenerateTenantActions($this->w->tenant->id, wordUntil: CarbonImmutable::now()->getTimestamp() - 1));

    expect($this->writer->requests)->toBe([])->and(Action::query()->sole()->text_source)->toBe('template');
});

test('the time the generation takes counts towards the wording deadline', function () {
    $this->source->onEvaluate = fn () => $this->travel(400)->seconds();

    generateTenant($this->w);

    expect($this->writer->requests)->toBe([])->and(Action::query()->sole()->text_source)->toBe('template');
});

test('a long language tag is stored with the model text', function () {
    $this->w->market->forceFill(['language' => 'zh-Hant-TW', 'country' => 'TW'])->save();

    generateTenant($this->w);

    expect(Action::query()->sole()->locale)->toBe('zh-Hant-TW')->and(Action::query()->sole()->text_source)->toBe('model');
});

test('without a model text the template locale is the nearest of nb, sv and en', function () {
    $this->w->market->forceFill(['language' => 'da', 'country' => 'DK'])->save();
    config(['services.anthropic.key' => '']);

    generateTenant($this->w);

    expect(Action::query()->sole()->locale)->toBe('en')->and(Action::query()->sole()->title)->toBe('Get covered on branschsajt.se');
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
