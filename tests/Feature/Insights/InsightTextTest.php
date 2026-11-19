<?php

// U12, docs/spec.md 14.2 and ADR 0038: the insight line. Claude Sonnet 5.5 writes a template with placeholders (a
// fake here, no paid call) and the code fills in the figures from the database, so the text never shows a number
// that differs from the page.

use App\ActionEngine\Writing\ActionWriterFailed;
use App\AnthropicBudget\Account;
use App\Insights\InsightTextGuard;
use App\Insights\InsightTextWriter;
use App\Models\AggDaily;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Insight;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\TenantContext;
use App\Usage\PlatformPaidUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Insights\FakeInsightWriter;
use Tests\Support\Metrics\MetricsWorld;

const BRAND_TEXT = 'Synligheten er {visibility_now} og endret seg med {visibility_change}. Motoren med størst endring ligger på {engine_1_now}.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    Cache::flush();
    config(['services.anthropic.key' => 'synthetic-key', 'ekko.insights.enabled' => true]);
    $this->w = MetricsWorld::create();
    $this->engine2 = Engine::factory()->create();
    MarketEngine::factory()->create(['market_id' => $this->w->market->id, 'engine_key' => $this->w->engine->key]);
    MarketEngine::factory()->create(['market_id' => $this->w->market->id, 'engine_key' => $this->engine2->key]);
    // Visibility: 28 of 70 now (40 %), 31 of 70 before (44.3 %). Engine 1 up 15 points, engine 2 down 30 points.
    foreach ([
        ['2026-11-10', $this->w->engine, $this->w->p1, 40, 22], ['2026-10-10', $this->w->engine, $this->w->p1, 40, 16],
        ['2026-11-10', $this->engine2, $this->w->p2, 30, 6], ['2026-10-10', $this->engine2, $this->w->p2, 30, 15],
    ] as [$date, $engine, $prompt, $responses, $mentioned]) {
        AggDaily::factory()->create([
            'market_id' => $this->w->market->id, 'engine_key' => $engine->key, 'prompt_id' => $prompt->id, 'date' => $date,
            'responses' => $responses, 'brand_mentioned' => $mentioned, 'brand_recommended' => intdiv($mentioned, 2),
            'sum_position' => $mentioned * 2, 'count_position' => $mentioned, 'own_cited' => intdiv($mentioned, 3),
        ]);
    }
    $this->owner = $this->w->tenant->owner;
    $this->owner->switchTeam($this->w->tenant);
    $this->writer = new FakeInsightWriter(FakeInsightWriter::json(BRAND_TEXT));
    app()->instance(InsightTextWriter::class, $this->writer);
});

afterEach(function () {
    // The independent budget connection commits outside the test transaction (ADR 0023).
    $budget = Account::independent()->db();
    $budget->statement("SET lock_timeout = '2s'");
    $budget->table('anthropic_budget_holds')->delete();
    $budget->table('anthropic_budget_cycles')->delete();
    $budget->table('anthropic_credit_spend')->where('entry', 'like', 'insight:%')->delete();
    DB::purge('anthropic_budget');
});

function brandUrl(MetricsWorld $w, string $query = ''): string
{
    return '/brands/'.$w->brand->id.$query;
}

/** @return array<string, mixed> the deferred insight prop of a brand page, or null */
function brandInsight(mixed $test, MetricsWorld $w, string $query = ''): ?array
{
    $insight = false;
    $test->actingAs($w->tenant->owner)->get(brandUrl($w, $query))->assertOk()->assertInertia(function ($page) use (&$insight) {
        $page->missing('insight')->loadDeferredProps(function ($deferred) use (&$insight) {
            $insight = $deferred->toArray()['props']['insight'] ?? null;
        });
    });

    return $insight;
}

/** The numbers written in a text, as the dashboard writes them (decimal comma or point). */
function textNumbers(string $text): array
{
    preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $found);

    return array_map(fn (string $n): float => (float) str_replace(',', '.', $n), $found[0]);
}

test('the model writes a template and the code fills in the figures from the database', function () {
    $insight = brandInsight($this, $this->w);

    expect($insight['text'])->toBe("Synligheten er 40\u{00A0}% og endret seg med 4,3 prosentpoeng. Motoren med størst endring ligger på 20\u{00A0}%.")
        ->and($insight['label'])->toBe('AI-oppsummering')
        ->and($insight['generated_on'])->toBe('2026-11-16');

    // The model gets directions and placeholder names, never the figures.
    $request = $this->writer->requests[0];
    expect($request->user)->toContain('visibility_now')->toContain('"direction":"down"')->toContain('engine_1_now')
        ->and($request->user)->not->toContain('40')->not->toContain('0.4')
        ->and($request->system)->toContain('Write in Norwegian');
});

test('no number in the text deviates from the numbers on the page (U12 acceptance)', function () {
    $props = null;
    $insight = false;
    $this->actingAs($this->owner)->get(brandUrl($this->w))->assertOk()->assertInertia(function ($page) use (&$props, &$insight) {
        $props = $page->toArray()['props'];
        $page->loadDeferredProps(function ($deferred) use (&$insight) {
            $insight = $deferred->toArray()['props']['insight'] ?? null;
        });
    });

    // Everything the page shows: every headline value, the value in the previous period, the change in points and
    // the value per engine, as the cards write them (percent with at most one decimal).
    $page = [];
    foreach (['visibility', 'recommendation_rate', 'share_of_voice', 'citation_rate'] as $metric) {
        $now = $props['summary'][$metric]['value'];
        $before = $props['previous'][$metric]['value'];
        $page[] = round($now * 100, 1);
        $page[] = round(abs($now - $before) * 100, 1);
    }
    foreach ($props['engines'] as $engine) {
        $page[] = round($engine['visibility']['value'] * 100, 1);
    }

    $numbers = textNumbers($insight['text']);
    expect($numbers)->not->toBeEmpty();
    foreach ($numbers as $number) {
        expect($page)->toContain($number);
    }
});

test('a text with a number the model wrote itself is refused and nothing is shown', function (string $text) {
    $this->writer->reply = FakeInsightWriter::json($text);

    expect(brandInsight($this, $this->w))->toBeNull();

    $row = Insight::query()->sole();
    expect($row->status)->toBe('unavailable')->and($row->reason)->toBe('refused')->and($row->template)->toBeNull()
        ->and(UsageEvent::query()->where('kind', 'insight')->count())->toBe(1);
})->with([
    'a figure' => 'Synligheten økte med 12 prosentpoeng.',
    'next to letters' => 'Synligheten økte 5x.',
    'a year' => 'Siden 2025 har synligheten falt.',
    'an unknown placeholder' => 'Synligheten er {made_up}.',
]);

test('the guard accepts placeholders and names with digits and refuses everything else', function () {
    $guard = new InsightTextGuard;
    $placeholders = ['visibility_now', 'visibility_change'];
    $names = ['GPT-5', 'Fjordkaffe'];
    $json = fn (string $text): string => FakeInsightWriter::json($text);

    expect($guard->parse($json('Fjordkaffe i GPT-5: {visibility_now}, {visibility_change}.'), $placeholders, $names, 320))->toBe('Fjordkaffe i GPT-5: {visibility_now}, {visibility_change}.')
        ->and($guard->parse($json('Fjordkaffe i GPT-4: {visibility_now}.'), $placeholders, $names, 320))->toBeNull()
        ->and($guard->parse($json('Opp {visibility_now} {other}.'), $placeholders, $names, 320))->toBeNull()
        ->and($guard->parse($json('Opp {visibility_now'), $placeholders, $names, 320))->toBeNull()
        ->and($guard->parse($json('Opp {visibility_now}.'), $placeholders, $names, 10))->toBeNull()
        ->and($guard->parse($json(''), $placeholders, $names, 320))->toBeNull()
        ->and($guard->parse('{"txt": "x"}', $placeholders, $names, 320))->toBeNull()
        ->and($guard->parse('not json', $placeholders, $names, 320))->toBeNull();
});

test('the text is stored per day and filter combination and is not regenerated on a view', function () {
    brandInsight($this, $this->w);
    brandInsight($this, $this->w);
    brandInsight($this, $this->w, '?days=28');
    expect($this->writer->requests)->toHaveCount(1)->and(Insight::query()->count())->toBe(1);

    // Another filter combination is another text; so is another day.
    brandInsight($this, $this->w, '?engine='.$this->w->engine->key);
    expect($this->writer->requests)->toHaveCount(2);

    $this->travel(1)->days();
    brandInsight($this, $this->w);
    expect($this->writer->requests)->toHaveCount(3)->and(Insight::query()->count())->toBe(3);
});

test('a stored text shows the figures as they are now, and is hidden if a change turned the other way', function () {
    expect(brandInsight($this, $this->w)['text'])->toContain('4,3 prosentpoeng');

    // More answers arrive later the same day: the figures follow the page, the text is not rewritten.
    AggDaily::factory()->create([
        'market_id' => $this->w->market->id, 'engine_key' => $this->w->engine->key, 'prompt_id' => $this->w->p1->id, 'date' => '2026-11-11',
        'responses' => 10, 'brand_mentioned' => 5, 'brand_recommended' => 1, 'sum_position' => 5, 'count_position' => 5, 'own_cited' => 1,
    ]);
    Cache::flush();
    $later = brandInsight($this, $this->w);
    expect($later['text'])->toContain("41,3\u{00A0}%")->and($later['text'])->toContain('endret seg med 3 prosentpoeng')
        ->and($this->writer->requests)->toHaveCount(1);

    // Visibility now above the previous period: "endret seg" stays true, but the direction was recorded as down.
    AggDaily::factory()->create([
        'market_id' => $this->w->market->id, 'engine_key' => $this->w->engine->key, 'prompt_id' => $this->w->p1->id, 'date' => '2026-11-12',
        'responses' => 10, 'brand_mentioned' => 10, 'brand_recommended' => 1, 'sum_position' => 5, 'count_position' => 5, 'own_cited' => 1,
    ]);
    Cache::flush();
    expect(brandInsight($this, $this->w))->toBeNull()->and($this->writer->requests)->toHaveCount(1);
});

test('the call is booked as insight usage that counts against the tenant quota, and settled on the account', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);

    brandInsight($this, $this->w);

    $usage = UsageEvent::query()->sole();
    expect($usage->kind)->toBe('insight')->and($usage->calls)->toBe(1)->and($usage->billed_to)->toBe('platform')
        ->and($usage->input_tokens)->toBe(1500)->and($usage->output_tokens)->toBe(120)
        ->and($usage->cost_usd)->toBe('0.00420000')
        ->and(PlatformPaidUsage::isPlatformPaid('insight'))->toBeFalse()
        ->and(PlatformPaidUsage::exclude(UsageEvent::query())->count())->toBe(1)
        ->and(DB::table('anthropic_credit_spend')->where('entry', $usage->anthropic_operation)->value('cost_usd'))->toBe('0.00420000')
        ->and(DB::table('anthropic_budget_holds')->where('operation', $usage->anthropic_operation)->whereNotNull('settled_at')->count())->toBe(1)
        ->and(Insight::query()->sole()->usage_event_id)->toBe($usage->id);
});

test('the model id is configuration', function () {
    config(['ekko.anthropic.insight.model' => 'claude-test-model']);

    brandInsight($this, $this->w);

    expect(Insight::query()->sole()->model)->toBe('claude-test-model');
});

test('each call writes one structured log line with internal IDs and no text', function () {
    Log::spy();

    brandInsight($this, $this->w);

    $usage = UsageEvent::query()->sole();
    Log::shouldHaveReceived('info')->with('Insight call.', Mockery::on(fn (array $context): bool => $context === [
        'tenant' => $this->w->tenant->id,
        'brand' => $this->w->brand->id,
        'scope' => 'brand',
        'operation' => $usage->anthropic_operation,
        'outcome' => 'written',
        'usage_event' => $usage->id,
        'input_tokens' => 1500,
        'output_tokens' => 120,
        'cost_usd' => '0.00420000',
    ]))->once();
});

test('in reduced mode no call is made and no insight line is shown; it is tried again later', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);
    Account::independent()->markExhausted();

    expect(brandInsight($this, $this->w))->toBeNull()
        ->and($this->writer->requests)->toBe([])
        ->and(UsageEvent::query()->count())->toBe(0)
        ->and(Insight::query()->sole()->reason)->toBe('reduced');

    // Within the retry window nothing is tried; after it, and with room on the account, the text is written.
    brandInsight($this, $this->w);
    expect($this->writer->requests)->toBe([]);
    Account::independent()->db()->table('anthropic_budget_cycles')->delete();
    $this->travel(61)->minutes();
    expect(brandInsight($this, $this->w))->not->toBeNull()->and($this->writer->requests)->toHaveCount(1);
});

test('a known provider error shows nothing, releases the reservation and is tried again later; an unknown outcome keeps it and is not', function (bool $known) {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);
    $this->writer->failure = new ActionWriterFailed('x', known: $known);

    expect(brandInsight($this, $this->w))->toBeNull();
    expect(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe($known ? 0 : 1)
        ->and(UsageEvent::query()->count())->toBe(0);

    $this->writer->failure = null;
    $this->travel(61)->minutes();
    brandInsight($this, $this->w);
    expect($this->writer->requests)->toHaveCount($known ? 2 : 1);
})->with(['known' => true, 'unknown' => false]);

test('a credit balance error stops further Anthropic calls for the period', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '10', 'ekko.anthropic.cycle_start_day' => 1]);
    $this->writer->failure = new ActionWriterFailed('x', known: true, creditExhausted: true);

    brandInsight($this, $this->w);

    expect(app(Account::class)->exhausted(app(Account::class)->cycle()))->toBeTrue();
});

test('a tenant whose quota is used up gets no insight call, and the daily cap holds', function () {
    DB::table('teams')->where('id', $this->w->tenant->id)->update(['monthly_call_quota_override' => 1]);
    UsageEvent::factory()->create(['kind' => 'engine_call', 'calls' => 1, 'occurred_at' => now()]);

    expect(brandInsight($this, $this->w))->toBeNull()
        ->and($this->writer->requests)->toBe([])
        ->and(Insight::query()->sole()->reason)->toBe('quota');

    DB::table('teams')->where('id', $this->w->tenant->id)->update(['monthly_call_quota_override' => null]);
    config(['ekko.insights.max_per_tenant_per_day' => 1]);
    $this->travel(61)->minutes();
    expect(brandInsight($this, $this->w))->not->toBeNull();
    expect(brandInsight($this, $this->w, '?engine='.$this->engine2->key))->toBeNull()
        ->and($this->writer->requests)->toHaveCount(1);
});

test('changes with too few answers are not findings and nothing is called without data', function () {
    AggDaily::query()->where('date', '2026-10-10')->update(['responses' => 3, 'brand_mentioned' => 1, 'brand_recommended' => 0, 'sum_position' => 1, 'count_position' => 1, 'own_cited' => 0]);

    $this->writer->reply = FakeInsightWriter::json('Synligheten er {visibility_now}.');
    $insight = brandInsight($this, $this->w);

    $request = $this->writer->requests[0];
    expect($insight['text'])->toBe("Synligheten er 40\u{00A0}%.")
        ->and($request->user)->not->toContain('visibility_change')->and($request->user)->toContain('too few answers');

    AggDaily::query()->update(['responses' => 3, 'brand_mentioned' => 1, 'brand_recommended' => 0, 'sum_position' => 1, 'count_position' => 1, 'own_cited' => 0]);
    Cache::flush();
    $this->travel(1)->days();
    expect(brandInsight($this, $this->w))->toBeNull()->and($this->writer->requests)->toHaveCount(1);
});

test('without an Anthropic key, or while switched off, nothing is shown and nothing is called', function (array $config) {
    config($config);

    expect(brandInsight($this, $this->w))->toBeNull()->and($this->writer->requests)->toBe([])->and(Insight::query()->count())->toBe(0);
})->with([
    'no key' => [['services.anthropic.key' => '']],
    'switched off' => [['ekko.insights.enabled' => false]],
]);

test('the text is written in the language of the user', function () {
    $this->owner->forceFill(['locale' => 'sv'])->save();
    app()->setLocale('sv');
    $this->writer->reply = FakeInsightWriter::json('Synligheten är {visibility_now}, ändring {visibility_change}.');

    $insight = brandInsight($this, $this->w);

    expect($this->writer->requests[0]->system)->toContain('Write in Swedish')
        ->and($insight['label'])->toBe('AI-sammanfattning')->and($insight['text'])->toContain('4,3 procentenheter');
});

test('the overview text covers all brands, is stored per day and shows the page figures', function () {
    $second = app(TenantContext::class)->run($this->w->tenant->id, fn () => Brand::factory()->create(['name' => 'Havbris']));
    $market = app(TenantContext::class)->run($this->w->tenant->id, fn () => Market::factory()->for($second)->create());
    foreach ([['2026-11-10', 20, 15], ['2026-10-10', 20, 5]] as [$date, $responses, $mentioned]) {
        AggDaily::factory()->create([
            'market_id' => $market->id, 'engine_key' => $this->w->engine->key, 'date' => $date, 'responses' => $responses,
            'brand_mentioned' => $mentioned, 'brand_recommended' => 1, 'sum_position' => 1, 'count_position' => 1, 'own_cited' => 1,
        ]);
    }
    $this->writer->reply = FakeInsightWriter::json('Havbris økte med {brand_1_change} til {brand_1_now}. Fjordkaffe endret seg med {brand_2_change}.');

    $props = null;
    $insight = null;
    foreach ([1, 2] as $_) {
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertInertia(function ($page) use (&$props, &$insight) {
            $props = $page->toArray()['props'];
            $page->missing('insight')->loadDeferredProps(function ($deferred) use (&$insight) {
                $insight = $deferred->toArray()['props']['insight'] ?? null;
            });
        });
    }

    expect($insight['text'])->toBe("Havbris økte med 50 prosentpoeng til 75\u{00A0}%. Fjordkaffe endret seg med 4,3 prosentpoeng.")
        ->and($this->writer->requests)->toHaveCount(1)
        ->and(Insight::query()->sole()->scope)->toBe('overview')
        ->and($this->writer->requests[0]->system)->toContain('across the brands');
    $pageNumbers = [];
    foreach ($props['brands'] as $brand) {
        $pageNumbers[] = round($brand['visibility']['value'] * 100, 1);
        $pageNumbers[] = round(abs($brand['visibility']['value'] - $brand['previous_visibility']['value']) * 100, 1);
    }
    foreach (textNumbers($insight['text']) as $number) {
        expect($pageNumbers)->toContain($number);
    }
});

test('another tenant never sees the stored text or the brand', function () {
    brandInsight($this, $this->w);
    $other = Team::factory()->tenant()->create();
    $otherOwner = $other->owner;
    $otherOwner->switchTeam($other);

    $this->actingAs($otherOwner)->get(brandUrl($this->w))->assertNotFound();
    expect(app(TenantContext::class)->run($other->id, fn () => Insight::query()->count()))->toBe(0)
        ->and(app(TenantContext::class)->run($this->w->tenant->id, fn () => Insight::query()->count()))->toBe(1);
});

test('a movement placeholder keeps pointing at the engine the text names; if the ranking moves on, the text is hidden', function () {
    $this->writer->reply = FakeInsightWriter::json('Mest endring i motoren: {engine_1_now}.');
    expect(brandInsight($this, $this->w)['text'])->toContain("20\u{00A0}%");

    // Engine 1 now moves more than engine 2 did: the first mover is another engine, so the stored sentence no longer fits.
    AggDaily::query()->where('engine_key', $this->w->engine->key)->where('date', '2026-11-10')->update(['responses' => 40, 'brand_mentioned' => 40, 'brand_recommended' => 1, 'sum_position' => 1, 'count_position' => 1, 'own_cited' => 1]);
    Cache::flush();

    expect(brandInsight($this, $this->w))->toBeNull()->and($this->writer->requests)->toHaveCount(1);
});

test('the text is hidden when the direction of a finding it names turns, even without a change placeholder', function () {
    $this->writer->reply = FakeInsightWriter::json('Motoren falt til {engine_1_now}.');
    expect(brandInsight($this, $this->w))->not->toBeNull();

    // Engine 2 (the first mover, down) now rises above its earlier level, and is still the biggest mover.
    AggDaily::query()->where('engine_key', $this->engine2->key)->where('date', '2026-11-10')->update(['brand_mentioned' => 29, 'brand_recommended' => 1, 'sum_position' => 1, 'count_position' => 1, 'own_cited' => 1]);
    Cache::flush();

    expect(brandInsight($this, $this->w))->toBeNull()->and($this->writer->requests)->toHaveCount(1);
});
