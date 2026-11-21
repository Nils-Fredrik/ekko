<?php

// T24, docs/spec.md 11.1: every alert type has a test that fires and a test that does not. "Today" is Monday
// 2026-11-16, so the latest window is 2026-11-09 to 2026-11-15 and the one before it 2026-11-02 to 2026-11-08.

use App\Alerts\AlertEvaluator;
use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Engine;
use App\Models\EntityDecision;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
});

/**
 * Runs the evaluator for the tenant and returns the types of the alerts it created.
 *
 * @return list<string>
 */
function evaluated(): array
{
    return array_map(
        fn (Alert $alert) => $alert->alertRule->type,
        app(AlertEvaluator::class)->evaluate(CarbonImmutable::now(), 'Europe/Oslo'),
    );
}

function aggRow(MetricsWorld $w, string $date, array $attributes, ?Prompt $prompt = null, ?Engine $engine = null): AggDaily
{
    return AggDaily::factory()->create([
        'market_id' => $w->market->id,
        'engine_key' => ($engine ?? $w->engine)->key,
        'prompt_id' => ($prompt ?? $w->p1)->id,
        'date' => $date,
        'competitor_mentions' => [],
        ...$attributes,
    ]);
}

/**
 * Answers to a core prompt where a competitor stands first in $first of $count list answers.
 */
function listAnswers(MetricsWorld $w, Prompt $prompt, string $day, int $count, int $first): void
{
    for ($i = 0; $i < $count; $i++) {
        $w->answer($prompt, "{$day} 10:00:00", [
            'type' => 'list',
            'brand' => ['pos' => 2],
            'competitors' => [$w->c1->id => $i < $first ? 1 : 3],
        ]);
    }
}

// visibility_drop ----------------------------------------------------------------------------------------

test('visibility_drop fires when visibility falls by more than the threshold with enough answers', function () {
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 24]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 16]);

    $alerts = app(AlertEvaluator::class)->evaluate(CarbonImmutable::now(), 'Europe/Oslo');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->alertRule->type)->toBe('visibility_drop')
        ->and($alerts[0]->alertRule->brand_id)->toBe($this->w->brand->id)
        ->and($alerts[0]->payload['market_id'])->toBe($this->w->market->id)
        ->and($alerts[0]->payload['engine_key'])->toBe($this->w->engine->key)
        ->and($alerts[0]->payload['previous'])->toBe(['value' => 0.6, 'n' => 40, 'low_sample' => false])
        ->and($alerts[0]->payload['current']['value'])->toBe(0.4)
        ->and($alerts[0]->payload['drop_points'])->toEqual(20);
});

test('visibility_drop does not fire at exactly the threshold', function () {
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 24]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 20]);

    expect(evaluated())->toBe([]);
});

test('visibility_drop does not fire on a rise or with fewer than 30 answers in either week', function () {
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 20]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 30]);
    expect(evaluated())->toBe([]);

    $other = Engine::factory()->create();
    aggRow($this->w, '2026-11-04', ['responses' => 29, 'brand_mentioned' => 29], engine: $other);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 0], engine: $other);
    $third = Engine::factory()->create();
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 40], engine: $third);
    aggRow($this->w, '2026-11-12', ['responses' => 29, 'brand_mentioned' => 0], engine: $third);

    expect(evaluated())->toBe([]);
});

test('visibility_drop uses the threshold of the rule', function () {
    AlertRule::factory()->create(['brand_id' => $this->w->brand->id, 'type' => 'visibility_drop', 'threshold' => '25.0000']);
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 24]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 16]);

    expect(evaluated())->toBe([]);
});

// competitor_takeover ------------------------------------------------------------------------------------

test('competitor_takeover fires when a competitor goes over the threshold on a core prompt', function () {
    $this->w->p1->update(['priority' => 'core']);
    listAnswers($this->w, $this->w->p1, '2026-11-04', 6, 0);
    listAnswers($this->w, $this->w->p1, '2026-11-10', 6, 4);

    $alerts = app(AlertEvaluator::class)->evaluate(CarbonImmutable::now(), 'Europe/Oslo');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->alertRule->type)->toBe('competitor_takeover')
        ->and($alerts[0]->payload['competitor_id'])->toBe($this->w->c1->id)
        ->and($alerts[0]->payload['prompt_id'])->toBe($this->w->p1->id)
        ->and($alerts[0]->payload['previous']['value'])->toEqual(0)
        ->and($alerts[0]->payload['current']['n'])->toBe(6);
});

test('competitor_takeover does not fire at exactly the threshold, or when the competitor already led', function () {
    $this->w->p1->update(['priority' => 'core']);
    listAnswers($this->w, $this->w->p1, '2026-11-04', 6, 0);
    listAnswers($this->w, $this->w->p1, '2026-11-10', 6, 3);
    expect(evaluated())->toBe([]);

    $this->w->p2->update(['priority' => 'core']);
    listAnswers($this->w, $this->w->p2, '2026-11-04', 6, 4);
    listAnswers($this->w, $this->w->p2, '2026-11-10', 6, 5);
    expect(evaluated())->toBe([]);
});

test('competitor_takeover ignores prompts that are not core and windows with too few list answers', function () {
    listAnswers($this->w, $this->w->p1, '2026-11-04', 6, 0);
    listAnswers($this->w, $this->w->p1, '2026-11-10', 6, 6);
    expect(evaluated())->toBe([]);

    $this->w->p1->update(['priority' => 'core']);
    $this->w->c1->update(['is_active' => false]);
    expect(evaluated())->toBe([]);

    $this->w->c1->update(['is_active' => true]);
    $this->w->p2->update(['priority' => 'core']);
    listAnswers($this->w, $this->w->p2, '2026-11-04', 6, 0);
    listAnswers($this->w, $this->w->p2, '2026-11-10', 4, 4);
    expect(array_count_values(evaluated()))->toBe(['competitor_takeover' => 1]);
});

// citation_lost ------------------------------------------------------------------------------------------

test('citation_lost fires when an own domain was cited in the previous round and not in the latest', function () {
    $this->w->p1->update(['priority' => 'core']);
    aggRow($this->w, '2026-11-14', ['responses' => 5, 'own_cited' => 3]);
    aggRow($this->w, '2026-11-15', ['responses' => 5, 'own_cited' => 0]);

    $alerts = app(AlertEvaluator::class)->evaluate(CarbonImmutable::now(), 'Europe/Oslo');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->alertRule->type)->toBe('citation_lost')
        ->and($alerts[0]->payload['prompt_id'])->toBe($this->w->p1->id)
        ->and($alerts[0]->payload['previous_round']['date'])->toBe('2026-11-14')
        ->and($alerts[0]->payload['latest_round']['date'])->toBe('2026-11-15');
});

test('citation_lost does not fire while the domain is still cited, for one round, or for a stale or non-core prompt', function () {
    $this->w->p1->update(['priority' => 'core']);
    aggRow($this->w, '2026-11-14', ['responses' => 5, 'own_cited' => 3]);
    aggRow($this->w, '2026-11-15', ['responses' => 5, 'own_cited' => 1]);
    expect(evaluated())->toBe([]);

    aggRow($this->w, '2026-11-15', ['responses' => 5, 'own_cited' => 0], $this->w->p2);
    expect(evaluated())->toBe([]);

    $this->w->p2->update(['priority' => 'core']);
    aggRow($this->w, '2026-11-02', ['responses' => 5, 'own_cited' => 3], $this->w->p2);
    aggRow($this->w, '2026-11-03', ['responses' => 5, 'own_cited' => 0], $this->w->p2);
    expect(evaluated())->toBe([]);
});

test('citation_lost does not fire for a prompt that was never cited', function () {
    $this->w->p1->update(['priority' => 'core']);
    aggRow($this->w, '2026-11-14', ['responses' => 5, 'own_cited' => 0]);
    aggRow($this->w, '2026-11-15', ['responses' => 5, 'own_cited' => 0]);

    expect(evaluated())->toBe([]);
});

// new_competitor -----------------------------------------------------------------------------------------

/**
 * $count answers on 2026-11-10 in the market, $unknown of them naming an unknown brand (in different spellings).
 */
function answersWithUnknown(MetricsWorld $w, int $count, int $unknown, string $name = 'Nordisk Bønne'): void
{
    for ($i = 0; $i < $count; $i++) {
        $w->answer($w->p1, '2026-11-10 10:00:00', [
            'brand' => ['pos' => 1],
            'unknown' => $i < $unknown ? ($i % 2 === 0 ? $name : mb_strtolower("  {$name}")) : false,
        ]);
    }
}

test('new_competitor fires when an unknown brand is in more than the threshold of the answers', function () {
    answersWithUnknown($this->w, 30, 4);

    $alerts = app(AlertEvaluator::class)->evaluate(CarbonImmutable::now(), 'Europe/Oslo');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->alertRule->type)->toBe('new_competitor')
        ->and(mb_strtolower($alerts[0]->payload['name']))->toBe('nordisk bønne')
        ->and($alerts[0]->payload['market_id'])->toBe($this->w->market->id)
        ->and($alerts[0]->payload['share']['n'])->toBe(30)
        ->and(round($alerts[0]->payload['share']['value'], 3))->toBe(0.133);
});

test('new_competitor does not fire at exactly the threshold', function () {
    answersWithUnknown($this->w, 30, 3);

    expect(evaluated())->toBe([]);
});

test('new_competitor needs enough answers in the market', function () {
    answersWithUnknown($this->w, 29, 20);

    expect(evaluated())->toBe([]);
});

test('new_competitor skips a name with a decision and a name the brand knows under another spelling', function () {
    answersWithUnknown($this->w, 30, 6);
    answersWithUnknown($this->w, 30, 6, 'Nordlys AS');
    EntityDecision::query()->create(['brand_id' => $this->w->brand->id, 'name_key' => 'nordisk bønne', 'display_name' => 'Nordisk Bønne',
        'variants' => ['Nordisk Bønne'], 'decision' => 'other', 'alias_ids' => [], 'decided_at' => now()]);

    expect(evaluated())->toBe([]);
});

test('new_competitor does not count competitors the detection already knows', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->w->answer($this->w->p1, '2026-11-10 10:00:00', ['brand' => ['pos' => 1], 'competitors' => [$this->w->c1->id => 2]]);
    }

    expect(evaluated())->toBe([]);
});

// model_change -------------------------------------------------------------------------------------------

test('model_change fires when the dominant model of a day differs from the previous day', function () {
    foreach (['gpt-a', 'gpt-a', 'gpt-b'] as $i => $model) {
        $this->w->answer($this->w->p1, '2026-11-14 1'.$i.':00:00', ['model' => $model]);
    }
    foreach (['gpt-b', 'gpt-b', 'gpt-a'] as $i => $model) {
        $this->w->answer($this->w->p1, '2026-11-15 1'.$i.':00:00', ['model' => $model]);
    }

    $alerts = app(AlertEvaluator::class)->evaluate(CarbonImmutable::now(), 'Europe/Oslo');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->alertRule->type)->toBe('model_change')
        ->and($alerts[0]->alertRule->brand_id)->toBeNull()
        ->and($alerts[0]->payload)->toBe([
            'engine_key' => $this->w->engine->key, 'date' => '2026-11-15', 'from_model' => 'gpt-a', 'to_model' => 'gpt-b',
        ]);
});

test('model_change does not fire when the provider only alternates between versions during the day', function () {
    foreach (['gpt-a', 'gpt-a', 'gpt-a', 'gpt-b'] as $i => $model) {
        $this->w->answer($this->w->p1, '2026-11-14 1'.$i.':00:00', ['model' => $model]);
    }
    // The first answer of the 15th differs from the last of the 14th, so there is a model_changes marker,
    // but the dominant model is the same.
    foreach (['gpt-b', 'gpt-a', 'gpt-a'] as $i => $model) {
        $this->w->answer($this->w->p1, '2026-11-15 1'.$i.':00:00', ['model' => $model]);
    }

    expect(evaluated())->toBe([]);
});

test('model_change does not fire for an old change or for answers without a model id', function () {
    foreach (['2026-11-05' => 'gpt-a', '2026-11-06' => 'gpt-b', '2026-11-14' => 'gpt-b', '2026-11-15' => 'gpt-b'] as $day => $model) {
        $this->w->answer($this->w->p1, "{$day} 10:00:00", ['model' => $model]);
    }
    $this->w->answer($this->w->p1, '2026-11-15 11:00:00');

    expect(evaluated())->toBe([]);
});

test('model_change does not flip on a tie, and counts days in the tenant timezone', function () {
    // 23:30 UTC on the 14th is already the 15th in Oslo, so the 15th has the answers gpt-b, gpt-b.
    $this->w->answer($this->w->p1, '2026-11-14 10:00:00', ['model' => 'gpt-a']);
    $this->w->answer($this->w->p1, '2026-11-14 11:00:00', ['model' => 'gpt-b']);
    $this->w->answer($this->w->p1, '2026-11-14 23:30:00', ['model' => 'gpt-b']);
    $this->w->answer($this->w->p1, '2026-11-15 10:00:00', ['model' => 'gpt-a']);

    // 14th (Oslo): gpt-a, gpt-b tied, smallest id wins: gpt-a. 15th (Oslo): gpt-b and gpt-a tied, the previous
    // day's model wins: gpt-a. No change.
    expect(evaluated())->toBe([]);
});

// quota (created by the hourly usage rollup, ADR 0013) -----------------------------------------------------

test('quota alerts are created at 80 and 100 percent and not below', function () {
    $this->w->tenant->forceFill(['plan_id' => Plan::factory()->create(['monthly_call_quota' => 10, 'monthly_budget_usd' => null])->id])->save();
    UsageEvent::factory()->create(['occurred_at' => now(), 'calls' => 7, 'cost_usd' => '0.10']);

    $this->artisan('ekko:usage-rollup')->assertSuccessful();
    expect(Alert::query()->count())->toBe(0);

    UsageEvent::factory()->create(['occurred_at' => now(), 'calls' => 1, 'cost_usd' => '0.10']);
    $this->artisan('ekko:usage-rollup')->assertSuccessful();
    expect(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->sole()->alertRule->type)->toBe('quota')
        ->and(Alert::query()->sole()->payload['threshold_percent'])->toBe(80);

    UsageEvent::factory()->create(['occurred_at' => now(), 'calls' => 2, 'cost_usd' => '0.10']);
    $this->artisan('ekko:usage-rollup')->assertSuccessful();
    expect(Alert::query()->orderBy('id')->get()->pluck('payload.threshold_percent')->all())->toBe([80, 100]);
});

// Rules --------------------------------------------------------------------------------------------------

test('a rule that is turned off gives no alert, and rules are created with the defaults', function () {
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 24]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 16]);
    AlertRule::factory()->create(['brand_id' => $this->w->brand->id, 'type' => 'visibility_drop', 'is_active' => false]);

    expect(evaluated())->toBe([]);

    AlertRule::query()->where('type', 'visibility_drop')->update(['is_active' => true]);
    expect(evaluated())->toBe(['visibility_drop']);

    $rule = AlertRule::query()->where('type', 'competitor_takeover')->sole();
    expect((float) $rule->threshold)->toBe(50.0)
        ->and($rule->channels)->toBe(['in_app', 'email', 'slack'])
        ->and(AlertRule::query()->where('type', 'model_change')->sole()->brand_id)->toBeNull();
});

test('the same condition is alerted once within the cooldown and again after it', function () {
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 24]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 16]);

    expect(evaluated())->toBe(['visibility_drop'])
        ->and(evaluated())->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'Europe/Oslo'));
    aggRow($this->w, '2026-11-19', ['responses' => 40, 'brand_mentioned' => 8]);
    expect(evaluated())->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-24 08:00:00', 'Europe/Oslo'));
    expect(evaluated())->toBe(['visibility_drop']);
});

test('brands that are not active are not evaluated', function () {
    aggRow($this->w, '2026-11-04', ['responses' => 40, 'brand_mentioned' => 24]);
    aggRow($this->w, '2026-11-12', ['responses' => 40, 'brand_mentioned' => 16]);
    $this->w->brand->update(['status' => 'archived']);

    expect(evaluated())->toBe([]);
});

test('a model that flaps back is not alerted again within the cooldown', function () {
    foreach (['2026-11-14' => 'gpt-a', '2026-11-15' => 'gpt-b'] as $day => $model) {
        $this->w->answer($this->w->p1, "{$day} 10:00:00", ['model' => $model]);
    }
    expect(evaluated())->toBe(['model_change']);

    $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00', 'Europe/Oslo'));
    $this->w->answer($this->w->p1, '2026-11-16 10:00:00', ['model' => 'gpt-a']);

    expect(evaluated())->toBe([]);
});

test('a prompt that flips between cited and not cited is not alerted every other day', function () {
    $this->w->p1->update(['priority' => 'core']);
    aggRow($this->w, '2026-11-14', ['responses' => 5, 'own_cited' => 3]);
    aggRow($this->w, '2026-11-15', ['responses' => 5, 'own_cited' => 0]);
    expect(evaluated())->toBe(['citation_lost']);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:00:00', 'Europe/Oslo'));
    aggRow($this->w, '2026-11-16', ['responses' => 5, 'own_cited' => 2]);
    aggRow($this->w, '2026-11-17', ['responses' => 5, 'own_cited' => 0]);

    expect(evaluated())->toBe([]);
});

test('core prompts in an inactive market give no alerts', function () {
    $this->w->p1->update(['priority' => 'core']);
    aggRow($this->w, '2026-11-14', ['responses' => 5, 'own_cited' => 3]);
    aggRow($this->w, '2026-11-15', ['responses' => 5, 'own_cited' => 0]);
    $this->w->market->update(['is_active' => false]);

    expect(evaluated())->toBe([]);
});

test('a very long unknown brand name still gives an alert', function () {
    answersWithUnknown($this->w, 30, 10, str_repeat('Lang ', 50));

    expect(evaluated())->toBe(['new_competitor']);
});
