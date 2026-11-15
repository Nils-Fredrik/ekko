<?php

// ekko:aggregate and DailyAggregator (docs/spec.md 6.4, 7.5, 9; ADR 0012). Expected values are computed by hand.

use App\Jobs\AggregateTenantDays;
use App\Jobs\TenantJob;
use App\Metrics\DailyAggregator;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\ModelChange;
use App\Models\Run;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Metrics\MetricsWorld;

const OSLO_DAY = '2026-11-10'; // Europe/Oslo is UTC+1: the day is 2026-11-09 23:00Z up to 2026-11-10 23:00Z.

/**
 * Four answers on the day for prompt 1 and one engine, plus answers that must not count.
 *
 *  r1 list, brand at 1 (recommended, sentiment 0.8), competitor 1 at 2, a competitor of another brand and another
 *     brand (both ignored), cites own.example (own) and review.example
 *  r2 list, brand at 3 (sentiment -0.2), cites review.example twice
 *  r3 informational, no brand, competitors 1 and 2, cites review.example and news.example
 *  r4 no answer_type (the model stage failed), only an unknown company, at 23:30 local time
 *
 * @return array{0: MetricsWorld, 1: array<string, Model>}
 */
function aggregationDay(): array
{
    $w = MetricsWorld::create();
    $otherBrand = Brand::factory()->create();
    $otherCompetitor = Competitor::factory()->for($otherBrand)->create();

    $r = [];
    $r['r1'] = $w->answer($w->p1, '2026-11-10T09:00:00Z', [
        'type' => 'list', 'brand' => ['pos' => 1, 'rec' => true, 'sent' => 0.8], 'competitors' => [$w->c1->id => 2, $otherCompetitor->id => 3],
        'otherBrand' => $otherBrand->id, 'citations' => [['own.example', 'own'], ['review.example', 'third_party']],
    ]);
    $r['r2'] = $w->answer($w->p1, '2026-11-10T10:00:00Z', [
        'type' => 'list', 'brand' => ['pos' => 3, 'sent' => -0.2], 'citations' => [['review.example', 'third_party'], ['review.example', 'third_party']],
    ]);
    $r['r3'] = $w->answer($w->p1, '2026-11-10T11:00:00Z', [
        'type' => 'informational', 'competitors' => [$w->c1->id => null, $w->c2->id => null],
        'citations' => [['review.example', 'third_party'], ['news.example', 'third_party']],
    ]);
    $r['r4'] = $w->answer($w->p1, '2026-11-10T22:30:00Z', ['unknown' => true]);

    // Not on the day: 23:59 local the day before, and 00:30 local the day after.
    $w->answer($w->p1, '2026-11-09T22:59:00Z', ['brand' => ['pos' => 1]]);
    $w->answer($w->p1, '2026-11-10T23:30:00Z', ['brand' => ['pos' => 1]]);
    // Collected but not yet extracted.
    $r['late'] = $w->answer($w->p1, '2026-11-10T12:00:00Z', ['status' => 'collected', 'type' => 'list', 'brand' => ['pos' => 1, 'rec' => true, 'sent' => 0.5]]);

    return [$w, $r];
}

function aggregateDay(string $date = OSLO_DAY): array
{
    return app(DailyAggregator::class)->aggregate($date);
}

test('the counters of agg_daily are computed from answers, mentions and citations', function () {
    [$w] = aggregationDay();

    expect(aggregateDay())->toBe(['agg_daily' => 1, 'agg_source_daily' => 3, 'model_changes' => 0]);

    $row = AggDaily::query()->sole();
    expect($row->brand_id)->toBe($w->brand->id)
        ->and($row->market_id)->toBe($w->market->id)
        ->and($row->engine_key)->toBe($w->engine->key)
        ->and($row->prompt_id)->toBe($w->p1->id)
        ->and($row->date->format('Y-m-d'))->toBe(OSLO_DAY)
        ->and($row->responses)->toBe(4)
        ->and($row->brand_mentioned)->toBe(2)
        ->and($row->brand_recommended)->toBe(1)
        ->and($row->sum_position)->toBe(4)
        ->and($row->count_position)->toBe(2)
        ->and($row->list_responses)->toBe(2)
        ->and($row->first_place)->toBe(1)
        ->and($row->sum_sentiment)->toBe('0.600')
        ->and($row->count_sentiment)->toBe(2)
        ->and($row->own_cited)->toBe(1)
        ->and($row->competitor_only_responses)->toBe(1)
        ->and($row->competitor_mentions)->toEqual([$w->c1->id => 2, $w->c2->id => 1]);
});

test('agg_source_daily counts citations, answers with the domain and gap answers per domain', function () {
    aggregationDay();
    aggregateDay();

    $rows = AggSourceDaily::query()->get()->keyBy('domain');
    expect($rows->keys()->sort()->values()->all())->toBe(['news.example', 'own.example', 'review.example'])
        // review.example: 1 + 2 + 1 citations in 3 answers; r3 names competitors but not the brand, so it is a gap.
        ->and([$rows['review.example']->citations, $rows['review.example']->responses_with_domain, $rows['review.example']->gap_responses])->toBe([4, 3, 1])
        ->and([$rows['news.example']->citations, $rows['news.example']->responses_with_domain, $rows['news.example']->gap_responses])->toBe([1, 1, 1])
        // The brand's own domain is never a gap.
        ->and([$rows['own.example']->citations, $rows['own.example']->responses_with_domain, $rows['own.example']->gap_responses])->toBe([1, 1, 0]);
});

test('a day with no answers gives no rows', function () {
    MetricsWorld::create();

    expect(aggregateDay())->toBe(['agg_daily' => 0, 'agg_source_daily' => 0, 'model_changes' => 0])
        ->and(AggDaily::query()->count())->toBe(0);
});

test('an answer without any mention counts as an answer and nothing else', function () {
    $w = MetricsWorld::create();
    $w->answer($w->p2, '2026-11-10T09:00:00Z');

    aggregateDay();

    $row = AggDaily::query()->sole();
    expect([$row->responses, $row->brand_mentioned, $row->count_position, $row->list_responses, $row->count_sentiment, $row->own_cited, $row->competitor_only_responses])
        ->toBe([1, 0, 0, 0, 0, 0, 0])
        ->and($row->competitor_mentions)->toBe([]);
});

test('the brand position is its first list position and a brand with no position counts no position', function () {
    $w = MetricsWorld::create();
    $w->answer($w->p1, '2026-11-10T09:00:00Z', ['type' => 'list', 'brand' => ['pos' => null]]);

    aggregateDay();

    $row = AggDaily::query()->sole();
    // Mentioned in a list answer but not in a list item: counts as a list answer, not as first, with no position.
    expect([$row->brand_mentioned, $row->count_position, $row->sum_position, $row->list_responses, $row->first_place])->toBe([1, 0, 0, 1, 0]);
});

test('rows are split per prompt and engine', function () {
    $w = MetricsWorld::create();
    $other = Engine::factory()->create();
    $w->answer($w->p1, '2026-11-10T09:00:00Z', ['brand' => ['pos' => 1]]);
    $w->answer($w->p1, '2026-11-10T09:05:00Z', ['engine' => $other]);
    $w->answer($w->p2, '2026-11-10T09:10:00Z');

    expect(aggregateDay()['agg_daily'])->toBe(3)
        ->and(AggDaily::query()->where('prompt_id', $w->p1->id)->where('engine_key', $w->engine->key)->sole()->brand_mentioned)->toBe(1);
});

test('the day is the tenant day: a tenant west of UTC gets the evening hours on its own day', function () {
    $w = MetricsWorld::create('America/Los_Angeles'); // UTC-8 in November: the day is 08:00Z up to 08:00Z.
    $w->answer($w->p1, '2026-11-10T07:59:00Z'); // 2026-11-09 23:59 local
    $w->answer($w->p1, '2026-11-10T08:00:00Z'); // 2026-11-10 00:00 local
    $w->answer($w->p1, '2026-11-11T07:59:00Z'); // 2026-11-10 23:59 local
    $w->answer($w->p1, '2026-11-11T08:00:00Z'); // 2026-11-11 00:00 local

    aggregateDay('2026-11-10');
    expect(AggDaily::query()->sole()->responses)->toBe(2);
});

test('the aggregation is idempotent: a second run gives the same rows', function () {
    aggregationDay();

    aggregateDay();
    $snapshot = fn () => [
        AggDaily::query()->orderBy('id')->get()->makeHidden('updated_at')->toArray(),
        AggSourceDaily::query()->orderBy('id')->get()->makeHidden('updated_at')->toArray(),
    ];
    $first = $snapshot();
    aggregateDay();
    aggregateDay();
    $second = $snapshot();

    // Same ids, same values, no duplicates; rows are updated in place.
    expect($second)->toEqual($first)
        ->and(AggDaily::query()->count())->toBe(1)
        ->and(AggSourceDaily::query()->count())->toBe(3);
});

test('a run after late extraction results picks the late answer up', function () {
    [, $r] = aggregationDay();
    aggregateDay();
    expect(AggDaily::query()->sole()->responses)->toBe(4);

    Run::query()->whereKey($r['late']->run_id)->update(['status' => 'extracted']);
    aggregateDay();

    $row = AggDaily::query()->sole();
    // The late answer is a list answer with the brand at 1, recommended, sentiment 0.5.
    expect([$row->responses, $row->brand_mentioned, $row->brand_recommended, $row->sum_position, $row->count_position, $row->list_responses, $row->first_place, $row->sum_sentiment, $row->count_sentiment])
        ->toBe([5, 3, 2, 5, 3, 3, 2, '1.100', 3])
        ->and(AggDaily::query()->count())->toBe(1);
});

test('a run after a mention changed updates the row, and a second run changes nothing more', function () {
    [$w, $r] = aggregationDay();
    aggregateDay();

    $r['r2']->mentions()->where('entity_type', 'brand')->update(['list_position' => 1, 'is_recommended' => true, 'sentiment' => 0.4]);
    // The competitor mention in r3 is removed.
    $r['r3']->mentions()->where('entity_id', $w->c2->id)->delete();
    aggregateDay();
    $changed = AggDaily::query()->sole();

    // r2 is now first and recommended: positions 1 + 1 = 2, first_place 2, recommended 2, sentiment 0.8 + 0.4.
    expect([$changed->sum_position, $changed->first_place, $changed->brand_recommended, $changed->sum_sentiment])->toBe([2, 2, 2, '1.200'])
        ->and($changed->competitor_mentions)->toEqual([$w->c1->id => 2]);

    aggregateDay();
    expect(AggDaily::query()->sole()->makeHidden('updated_at')->toArray())->toEqual($changed->makeHidden('updated_at')->toArray());
});

test('rows that no longer have data are removed', function () {
    [, $r] = aggregationDay();
    aggregateDay();
    expect(AggSourceDaily::query()->count())->toBe(3);

    // The only answers citing news.example and own.example are taken away.
    $r['r1']->citations()->delete();
    $r['r3']->citations()->where('domain', 'news.example')->delete();
    aggregateDay();
    expect(AggSourceDaily::query()->pluck('domain')->all())->toBe(['review.example']);

    // All four answers are no longer extracted: the day has no rows.
    Run::query()->update(['status' => 'collected']);
    expect(aggregateDay())->toBe(['agg_daily' => 0, 'agg_source_daily' => 0, 'model_changes' => 0])
        ->and(AggDaily::query()->count())->toBe(0);
});

test('other days are not touched', function () {
    $w = MetricsWorld::create();
    $w->answer($w->p1, '2026-11-09T12:00:00Z', ['brand' => ['pos' => 1]]);
    $w->answer($w->p1, '2026-11-10T12:00:00Z');
    aggregateDay('2026-11-09');
    aggregateDay('2026-11-10');
    aggregateDay('2026-11-10');

    expect(AggDaily::query()->orderBy('date')->pluck('brand_mentioned')->all())->toBe([1, 0]);
});

test('a bad date is refused', function () {
    MetricsWorld::create();

    expect(fn () => aggregateDay('2026-02-30'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => aggregateDay('yesterday'))->toThrow(InvalidArgumentException::class);
});

test('aggregation needs a tenant in the context', function () {
    app(TenantContext::class)->forget();

    aggregateDay();
})->throws(MissingTenantContextException::class);

test('the job is a tenant job and runs under the tenant with row-level security', function () {
    [$w] = aggregationDay();
    $other = MetricsWorld::create();
    $other->answer($other->p1, '2026-11-10T12:00:00Z', ['brand' => ['pos' => 1]]);

    expect(new AggregateTenantDays($w->tenant->id, [OSLO_DAY]))->toBeInstanceOf(TenantJob::class);

    dispatch_sync(new AggregateTenantDays($w->tenant->id, [OSLO_DAY]));
    dispatch_sync(new AggregateTenantDays($w->tenant->id, [OSLO_DAY]));

    $counts = app(TenantContext::class)->run($w->tenant->id, fn () => AggDaily::query()->count());
    $otherCounts = app(TenantContext::class)->run($other->tenant->id, fn () => AggDaily::query()->count());
    expect($counts)->toBe(1)->and($otherCounts)->toBe(0);
});

test('the command aggregates every tenant under its own context and timezone', function () {
    $oslo = MetricsWorld::create();
    $oslo->answer($oslo->p1, '2026-11-10T09:00:00Z', ['brand' => ['pos' => 1]]);
    $oslo->answer($oslo->p1, '2026-11-10T23:30:00Z'); // the next day in Oslo
    $la = MetricsWorld::create('America/Los_Angeles');
    $la->answer($la->p1, '2026-11-10T07:00:00Z', ['brand' => ['pos' => 1]]); // 2026-11-09 local
    $la->answer($la->p1, '2026-11-11T07:00:00Z'); // 2026-11-10 local

    $this->artisan('ekko:aggregate', ['date' => OSLO_DAY, '--sync' => true])->assertSuccessful();
    $this->artisan('ekko:aggregate', ['date' => OSLO_DAY, '--sync' => true])->assertSuccessful();

    $summary = fn (MetricsWorld $w) => app(TenantContext::class)->run($w->tenant->id, fn () => AggDaily::query()->get(['responses', 'brand_mentioned'])->map->only(['responses', 'brand_mentioned'])->all());
    expect($summary($oslo))->toBe([['responses' => 1, 'brand_mentioned' => 1]])
        ->and($summary($la))->toBe([['responses' => 1, 'brand_mentioned' => 0]]);
});

test('the command takes a range and a tenant filter, and queues jobs without --sync', function () {
    $a = MetricsWorld::create();
    $a->answer($a->p1, '2026-11-09T12:00:00Z');
    $a->answer($a->p1, '2026-11-10T12:00:00Z');
    $b = MetricsWorld::create();
    $b->answer($b->p1, '2026-11-10T12:00:00Z');

    Queue::fake();
    $this->artisan('ekko:aggregate', ['--from' => '2026-11-09', '--to' => '2026-11-10', '--tenant' => [$a->tenant->id]])->assertSuccessful();
    Queue::assertPushed(AggregateTenantDays::class, 1);
    Queue::assertPushed(AggregateTenantDays::class, fn (AggregateTenantDays $job) => $job->tenantId === $a->tenant->id && $job->dates === ['2026-11-09', '2026-11-10']);
});

test('without a date the command aggregates today and the days back in the tenant timezone', function () {
    // 23:30Z on the 15th is 00:30 on the 16th in Oslo.
    Carbon::setTestNow('2026-11-15 23:30:00');
    $w = MetricsWorld::create();
    $w->answer($w->p1, '2026-11-15T10:00:00Z');
    $w->answer($w->p1, '2026-11-15T23:10:00Z');
    // Extracted late: collected three local days before today, still inside the window.
    $w->answer($w->p1, '2026-11-13T10:00:00Z');
    // Older than the window: not rebuilt without a date.
    $w->answer($w->p1, '2026-11-12T10:00:00Z');

    $this->artisan('ekko:aggregate', ['--sync' => true])->assertSuccessful();

    expect(AggDaily::query()->orderBy('date')->get()->map(fn ($row) => [$row->date->format('Y-m-d'), $row->responses])->all())
        ->toBe([['2026-11-13', 1], ['2026-11-15', 1], ['2026-11-16', 1]]);
    Carbon::setTestNow();
});

test('a long range is split into jobs of at most 31 days', function () {
    $w = MetricsWorld::create();
    Queue::fake();

    $this->artisan('ekko:aggregate', ['--from' => '2026-01-01', '--to' => '2026-03-15', '--tenant' => [$w->tenant->id]])->assertSuccessful();

    Queue::assertPushed(AggregateTenantDays::class, 3);
    Queue::assertPushed(AggregateTenantDays::class, fn (AggregateTenantDays $job) => count($job->dates) === 31);
});

test('the command refuses a bad date or range', function () {
    $this->artisan('ekko:aggregate', ['date' => 'not-a-date'])->assertFailed();
    $this->artisan('ekko:aggregate', ['--from' => '2026-11-10', '--to' => '2026-11-01'])->assertFailed();
    $this->artisan('ekko:aggregate', ['--to' => '2026-11-01'])->assertFailed();
});

test('model change markers: the first change on a day is recorded once', function () {
    $w = MetricsWorld::create();
    $other = Engine::factory()->create();
    // Day 1: the first model ever seen is not a change.
    $w->answer($w->p1, '2026-11-10T09:00:00Z', ['model' => 'model-a']);
    $w->answer($w->p1, '2026-11-10T09:00:00Z', ['model' => 'other-1', 'engine' => $other]);
    // Day 2: a, then b, then a again: one marker for the first change.
    $w->answer($w->p1, '2026-11-11T09:00:00Z', ['model' => 'model-a']);
    $w->answer($w->p1, '2026-11-11T10:00:00Z', ['model' => 'model-b']);
    $w->answer($w->p1, '2026-11-11T11:00:00Z', ['model' => 'model-a']);
    // Day 3: an answer without a model id is ignored.
    $w->answer($w->p1, '2026-11-12T09:00:00Z');

    expect(aggregateDay('2026-11-10')['model_changes'])->toBe(0)
        ->and(aggregateDay('2026-11-11')['model_changes'])->toBe(1)
        ->and(aggregateDay('2026-11-12')['model_changes'])->toBe(0);

    $marker = ModelChange::query()->sole();
    expect($marker->engine_key)->toBe($w->engine->key)
        ->and($marker->changed_on->format('Y-m-d'))->toBe('2026-11-11')
        ->and([$marker->from_model, $marker->to_model])->toBe(['model-a', 'model-b'])
        ->and($marker->changed_at->format('H:i'))->toBe('10:00');
});

test('model change markers follow a model_id_reported change across days and are idempotent', function () {
    $w = MetricsWorld::create();
    $w->answer($w->p1, '2026-11-10T09:00:00Z', ['model' => 'model-a']);
    $w->answer($w->p1, '2026-11-11T09:00:00Z', ['model' => 'model-b']);
    $w->answer($w->p1, '2026-11-12T09:00:00Z', ['model' => 'model-b']);

    foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $day) {
        aggregateDay($day);
    }
    $ids = ModelChange::query()->pluck('id', 'changed_on')->all();
    foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-11'] as $day) {
        aggregateDay($day);
    }

    expect(ModelChange::query()->count())->toBe(1)
        ->and(ModelChange::query()->pluck('id', 'changed_on')->all())->toEqual($ids);

    // A late answer on day 2 that changes the picture: the marker is recomputed for the day.
    $w->answer($w->p1, '2026-11-11T08:00:00Z', ['model' => 'model-c']);
    aggregateDay('2026-11-11');
    expect(ModelChange::query()->sole()->only(['from_model', 'to_model']))->toBe(['from_model' => 'model-a', 'to_model' => 'model-c']);
});
