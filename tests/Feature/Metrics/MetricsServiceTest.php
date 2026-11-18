<?php

// MetricsService: every formula of docs/spec.md 9 on known data sets, with hand-computed expected values.

use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\ModelChange;
use App\Models\Prompt;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

/**
 * Three agg_daily rows for one brand with two active competitors (c1, c2), an inactive one (c3) and a competitor
 * of another brand. Totals over all rows:
 *
 *   responses 40, brand_mentioned 28, brand_recommended 13, sum_position 36, count_position 26,
 *   list_responses 30, first_place 13, sum_sentiment 11.5, count_sentiment 28, own_cited 9,
 *   active competitor mentions 14 (c1) + 25 (c2) = 39
 *
 *   a  Mon 2026-11-02  engine 1  prompt 1 (topic 1, category)    10 answers
 *   b  Tue 2026-11-03  engine 1  prompt 2 (topic 2, comparison)  10 answers
 *   c  Mon 2026-11-10  engine 2  prompt 1                        20 answers
 *
 * @return array{w: MetricsWorld, engine2: Engine, topic1: Topic, topic2: Topic}
 */
function metricsData(): array
{
    $w = MetricsWorld::create();
    $engine2 = Engine::factory()->create();
    $topic1 = Topic::factory()->create(['brand_id' => $w->brand->id]);
    $topic2 = Topic::factory()->create(['brand_id' => $w->brand->id]);
    $w->p1->update(['topic_id' => $topic1->id, 'intent' => 'category', 'funnel_stage' => 'awareness']);
    $w->p2->update(['topic_id' => $topic2->id, 'intent' => 'comparison', 'funnel_stage' => 'decision']);

    $c3 = Competitor::factory()->for($w->brand)->create(['is_active' => false]);
    $w->c3 = $c3;

    $row = fn (array $attributes) => AggDaily::factory()->create(['market_id' => $w->market->id, ...$attributes]);
    $row(['date' => '2026-11-02', 'engine_key' => $w->engine->key, 'prompt_id' => $w->p1->id,
        'responses' => 10, 'brand_mentioned' => 5, 'brand_recommended' => 2, 'sum_position' => 10, 'count_position' => 4,
        'list_responses' => 6, 'first_place' => 2, 'sum_sentiment' => '2.000', 'count_sentiment' => 5, 'own_cited' => 3,
        'competitor_only_responses' => 4, 'competitor_mentions' => [$w->c1->id => 5, $w->c2->id => 5]]);
    $row(['date' => '2026-11-03', 'engine_key' => $w->engine->key, 'prompt_id' => $w->p2->id,
        'responses' => 10, 'brand_mentioned' => 3, 'brand_recommended' => 1, 'sum_position' => 6, 'count_position' => 2,
        'list_responses' => 4, 'first_place' => 1, 'sum_sentiment' => '-0.500', 'count_sentiment' => 3, 'own_cited' => 1,
        'competitor_only_responses' => 2, 'competitor_mentions' => [$w->c1->id => 9]]);
    $row(['date' => '2026-11-10', 'engine_key' => $engine2->key, 'prompt_id' => $w->p1->id,
        'responses' => 20, 'brand_mentioned' => 20, 'brand_recommended' => 10, 'sum_position' => 20, 'count_position' => 20,
        'list_responses' => 20, 'first_place' => 10, 'sum_sentiment' => '10.000', 'count_sentiment' => 20, 'own_cited' => 5,
        'competitor_only_responses' => 0, 'competitor_mentions' => [$w->c2->id => 20, $c3->id => 100]]);

    return ['w' => $w, 'engine2' => $engine2, 'topic1' => $topic1, 'topic2' => $topic2];
}

function metricsFilter(MetricsWorld $w, array $overrides = []): MetricsFilter
{
    return new MetricsFilter(...['brandId' => $w->brand->id, 'from' => '2026-11-01', 'to' => '2026-11-30', ...$overrides]);
}

function service(): MetricsService
{
    return app(MetricsService::class);
}

test('visibility is the share of answers that mention the brand', function () {
    ['w' => $w] = metricsData();

    $metric = service()->visibility(metricsFilter($w));

    expect($metric->value)->toBe(28 / 40)->and($metric->n)->toBe(40)->and($metric->isLowSample())->toBeFalse();
});

test('recommendation rate is the share of answers that recommend the brand', function () {
    ['w' => $w] = metricsData();

    expect(service()->recommendationRate(metricsFilter($w))->value)->toBe(13 / 40);
});

test('share of voice is the brand mentions over the brand and the active competitors, never inactive ones', function () {
    ['w' => $w] = metricsData();

    // 28 / (28 + 14 + 25): the inactive competitor (100 mentions) and other brands do not count.
    expect(service()->shareOfVoice(metricsFilter($w))->value)->toBe(28 / 67);

    // A competitor that becomes inactive leaves the denominator: 28 / (28 + 25).
    $w->c1->update(['is_active' => false]);
    expect(service()->shareOfVoice(metricsFilter($w))->value)->toBe(28 / 53);
});

test('share of voice is null with no mentions at all and 1 without competitors', function () {
    $w = MetricsWorld::create();
    AggDaily::factory()->create(['market_id' => $w->market->id, 'engine_key' => $w->engine->key, 'prompt_id' => $w->p1->id, 'date' => '2026-11-02',
        'responses' => 5, 'brand_mentioned' => 0, 'competitor_mentions' => []]);
    expect(service()->shareOfVoice(metricsFilter($w))->value)->toBeNull();

    AggDaily::query()->update(['brand_mentioned' => 2]);
    expect(service()->shareOfVoice(metricsFilter($w))->value)->toBe(1.0);
});

test('average position is the mean list position of the answers where the brand is in a list', function () {
    ['w' => $w] = metricsData();

    $metric = service()->averagePosition(metricsFilter($w));

    expect($metric->value)->toBe(36 / 26)->and($metric->n)->toBe(26);
});

test('average position is null when the brand is never in a list', function () {
    $w = MetricsWorld::create();
    AggDaily::factory()->create(['market_id' => $w->market->id, 'engine_key' => $w->engine->key, 'prompt_id' => $w->p1->id, 'date' => '2026-11-02',
        'responses' => 5, 'brand_mentioned' => 5, 'sum_position' => 0, 'count_position' => 0]);

    $metric = service()->averagePosition(metricsFilter($w));
    expect($metric->value)->toBeNull()->and($metric->n)->toBe(0);
});

test('first-place rate is the share of list answers where the brand is first', function () {
    ['w' => $w] = metricsData();

    $metric = service()->firstPlaceRate(metricsFilter($w));

    expect($metric->value)->toBe(13 / 30)->and($metric->n)->toBe(30);
});

test('citation rate is the share of answers that cite an own domain', function () {
    ['w' => $w] = metricsData();

    expect(service()->citationRate(metricsFilter($w))->value)->toBe(9 / 40);
});

test('sentiment is the mean over the brand mentions that have a sentiment', function () {
    ['w' => $w] = metricsData();

    $metric = service()->sentiment(metricsFilter($w));

    // (2.0 - 0.5 + 10.0) / 28
    expect($metric->value)->toBe(11.5 / 28)->and($metric->n)->toBe(28);
});

test('the summary has every metric, and toArray is the shape for the API', function () {
    ['w' => $w] = metricsData();

    $summary = service()->summary(metricsFilter($w));

    expect($summary->responses)->toBe(40)
        ->and($summary->toArray()['visibility'])->toBe(['value' => 0.7, 'n' => 40, 'low_sample' => false])
        ->and(array_keys($summary->toArray()))->toBe(['responses', 'visibility', 'recommendation_rate', 'share_of_voice', 'average_position', 'first_place_rate', 'citation_rate', 'sentiment']);
});

test('the combined dashboard preserves weighted totals, chronological days and every engine', function () {
    ['w' => $w, 'engine2' => $engine2] = metricsData();

    $dashboard = service()->dashboard(metricsFilter($w));

    expect($dashboard['summary']->responses)->toBe(40)
        ->and($dashboard['summary']->visibility->value)->toBe(0.7)
        ->and($dashboard['summary']->shareOfVoice->value)->toBe(28 / 67)
        ->and($dashboard['summary']->sentiment->value)->toBe(11.5 / 28)
        ->and(array_column($dashboard['series'], 'period'))->toBe(['2026-11-02', '2026-11-03', '2026-11-10'])
        ->and(array_map(fn (array $point) => $point['summary']->visibility->value, $dashboard['series']))->toBe([0.5, 0.3, 1.0])
        ->and($dashboard['engines'][$w->engine->key]->responses)->toBe(20)
        ->and($dashboard['engines'][$w->engine->key]->visibility->value)->toBe(0.4)
        ->and($dashboard['engines'][$engine2->key]->responses)->toBe(20)
        ->and(array_column($dashboard['voice'], 'mentions'))->toBe([28, 14, 25]);
});

test('the combined dashboard honors every dimension and agrees with the individual metric APIs', function () {
    ['w' => $w, 'engine2' => $engine2, 'topic2' => $topic2] = metricsData();

    $filters = [[], ['from' => '2026-11-03', 'to' => '2026-11-03'], ['marketIds' => [$w->market->id]],
        ['marketIds' => [0]], ['engineKeys' => [$engine2->key]], ['topicIds' => [$topic2->id]],
        ['intents' => ['comparison']], ['funnelStages' => ['decision']], ['promptIds' => [$w->p1->id]],
        ['engineKeys' => [$w->engine->key], 'topicIds' => [$topic2->id], 'intents' => ['comparison']]];

    foreach ($filters as $overrides) {
        $filter = metricsFilter($w, $overrides);
        $dashboard = service()->dashboard($filter);
        expect($dashboard['summary'])->toEqual(service()->summary($filter))
            ->and($dashboard['series'])->toEqual(service()->series($filter))
            ->and($dashboard['engines'])->toEqual(service()->breakdown($filter, 'engine'))
            ->and($dashboard['voice'])->toBe(service()->voiceParts($filter));
    }
});

test('the combined dashboard keeps zero competitors and empty periods without inventing series points', function () {
    ['w' => $w] = metricsData();
    $empty = service()->dashboard(metricsFilter($w, ['from' => '2027-01-01', 'to' => '2027-01-02']));
    expect($empty['summary']->responses)->toBe(0)->and($empty['summary']->visibility->value)->toBeNull()
        ->and($empty['series'])->toBe([])->and($empty['engines'])->toBe([])
        ->and(array_column($empty['voice'], 'mentions'))->toBe([0, 0, 0])
        ->and(array_column($empty['voice'], 'share'))->toBe([null, null, null]);

    AggDaily::query()->update(['competitor_mentions' => '[]']);
    $dashboard = service()->dashboard(metricsFilter($w));
    expect($dashboard['summary']->shareOfVoice->value)->toBe(1.0)
        ->and(array_column($dashboard['voice'], 'mentions'))->toBe([28, 0, 0]);

    Competitor::query()->update(['is_active' => false]);
    $dashboard = service()->dashboard(metricsFilter($w));
    expect($dashboard['voice'])->toHaveCount(1)->and($dashboard['voice'][0]['mentions'])->toBe(28)
        ->and($dashboard['summary']->responses)->toBe(40);
});

test('the combined dashboard reads the aggregates once even as competitors grow', function () {
    ['w' => $w] = metricsData();
    Competitor::factory()->count(20)->for($w->brand)->create(['is_active' => true]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $dashboard = service()->dashboard(metricsFilter($w));
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toHaveCount(2)
        ->and($dashboard['summary']->responses)->toBe(40)
        ->and($dashboard['voice'])->toHaveCount(23);
});

test('a reused metrics service never carries a dashboard across tenant contexts', function () {
    ['w' => $a] = metricsData();
    $metrics = service();
    expect($metrics->dashboard(metricsFilter($a))['summary']->responses)->toBe(40);
    $b = MetricsWorld::create();
    AggDaily::factory()->create(['market_id' => $b->market->id, 'engine_key' => $b->engine->key,
        'prompt_id' => $b->p1->id, 'date' => '2026-11-02', 'responses' => 100, 'brand_mentioned' => 90]);
    $foreign = $metrics->dashboard(metricsFilter($a));
    expect($foreign['summary']->responses)->toBe(0)->and($foreign['series'])->toBe([])
        ->and($foreign['engines'])->toBe([])->and($foreign['voice'])->toBe([
            ['id' => 'brand', 'name' => null, 'mentions' => 0, 'share' => null, 'n' => 0],
        ])->and($metrics->dashboard(metricsFilter($b))['summary']->visibility->value)->toBe(0.9);
});

test('zero answers give null values and n = 0, not an error', function () {
    ['w' => $w] = metricsData();

    $summary = service()->summary(metricsFilter($w, ['from' => '2026-12-01', 'to' => '2026-12-31']));

    expect($summary->responses)->toBe(0)
        ->and($summary->visibility->value)->toBeNull()
        ->and($summary->visibility->n)->toBe(0)
        ->and($summary->visibility->isLowSample())->toBeTrue()
        ->and($summary->shareOfVoice->value)->toBeNull()
        ->and($summary->averagePosition->value)->toBeNull()
        ->and($summary->firstPlaceRate->value)->toBeNull()
        ->and($summary->sentiment->value)->toBeNull();
});

test('values with fewer than ten answers are marked as low sample', function () {
    expect((new Metric(0.5, 9))->isLowSample())->toBeTrue()
        ->and((new Metric(0.5, 10))->isLowSample())->toBeFalse();

    ['w' => $w] = metricsData();
    expect(service()->visibility(metricsFilter($w, ['from' => '2026-11-03', 'to' => '2026-11-03']))->isLowSample())->toBeFalse();
    AggDaily::query()->where('date', '2026-11-03')->update(['responses' => 9]);
    expect(service()->visibility(metricsFilter($w, ['from' => '2026-11-03', 'to' => '2026-11-03']))->isLowSample())->toBeTrue();
});

test('the filter limits by engine, market, prompt, topic, intent, funnel stage and period', function () {
    ['w' => $w, 'engine2' => $engine2, 'topic1' => $topic1, 'topic2' => $topic2] = metricsData();
    $visibility = fn (array $overrides) => service()->visibility(metricsFilter($w, $overrides));

    // engine 1: rows a and b, 8 of 20
    expect($visibility(['engineKeys' => [$w->engine->key]])->value)->toBe(8 / 20)
        // engine 2: row c, 20 of 20
        ->and($visibility(['engineKeys' => [$engine2->key]])->value)->toBe(1.0)
        // both engines
        ->and($visibility(['engineKeys' => [$w->engine->key, $engine2->key]])->n)->toBe(40)
        // prompt 1: rows a and c, 25 of 30
        ->and($visibility(['promptIds' => [$w->p1->id]])->value)->toBe(25 / 30)
        // topic 2 and the comparison intent and the decision stage are all prompt 2: row b, 3 of 10
        ->and($visibility(['topicIds' => [$topic2->id]])->value)->toBe(0.3)
        ->and($visibility(['intents' => ['comparison']])->value)->toBe(0.3)
        ->and($visibility(['funnelStages' => ['decision']])->value)->toBe(0.3)
        ->and($visibility(['topicIds' => [$topic1->id], 'intents' => ['comparison']])->n)->toBe(0)
        // period: only row b, then rows b and c
        ->and($visibility(['from' => '2026-11-03', 'to' => '2026-11-03'])->n)->toBe(10)
        ->and($visibility(['from' => '2026-11-03', 'to' => '2026-11-10'])->n)->toBe(30)
        // the market
        ->and($visibility(['marketIds' => [$w->market->id]])->n)->toBe(40)
        ->and($visibility(['marketIds' => [$w->market->id + 999]])->n)->toBe(0);
});

test('weekly and monthly figures are sums over the days, computed in the query', function () {
    ['w' => $w] = metricsData();

    $week = service()->series(metricsFilter($w), 'week');
    $month = service()->series(metricsFilter($w), 'month');
    $day = service()->series(metricsFilter($w), 'day');

    // Week of Mon 2026-11-02: rows a and b, 8 of 20; week of Mon 2026-11-09: row c, 20 of 20.
    expect(array_column($week, 'period'))->toBe(['2026-11-02', '2026-11-09'])
        ->and($week[0]['summary']->visibility->value)->toBe(0.4)
        ->and($week[1]['summary']->visibility->value)->toBe(1.0)
        ->and($week[0]['summary']->shareOfVoice->value)->toBe(8 / (8 + 14 + 5))
        ->and(array_column($month, 'period'))->toBe(['2026-11-01'])
        ->and($month[0]['summary']->visibility->value)->toBe(28 / 40)
        ->and(array_column($day, 'period'))->toBe(['2026-11-02', '2026-11-03', '2026-11-10'])
        ->and($day[1]['summary']->visibility->value)->toBe(0.3);

    expect(AggDaily::query()->count())->toBe(3); // nothing was stored for the week or the month
});

test('a series refuses an unknown grain', function () {
    ['w' => $w] = metricsData();

    service()->series(metricsFilter($w), 'year');
})->throws(InvalidArgumentException::class);

test('a breakdown gives the summary per engine, market, prompt, topic and intent', function () {
    ['w' => $w, 'engine2' => $engine2, 'topic1' => $topic1, 'topic2' => $topic2] = metricsData();

    $byEngine = service()->breakdown(metricsFilter($w), 'engine');
    expect(array_keys($byEngine))->toEqualCanonicalizing([$w->engine->key, $engine2->key])
        ->and($byEngine[$w->engine->key]->visibility->value)->toBe(0.4)
        ->and($byEngine[$engine2->key]->visibility->value)->toBe(1.0);

    $byTopic = service()->breakdown(metricsFilter($w), 'topic');
    expect($byTopic[(string) $topic1->id]->visibility->value)->toBe(25 / 30)
        ->and($byTopic[(string) $topic2->id]->visibility->value)->toBe(0.3);

    $byIntent = service()->breakdown(metricsFilter($w), 'intent');
    expect(array_keys($byIntent))->toBe(['category', 'comparison'])
        ->and($byIntent['comparison']->responses)->toBe(10);

    expect(service()->breakdown(metricsFilter($w), 'prompt')[(string) $w->p1->id]->responses)->toBe(30)
        ->and(service()->breakdown(metricsFilter($w), 'market')[(string) $w->market->id]->responses)->toBe(40)
        ->and(service()->breakdown(metricsFilter($w), 'funnel_stage'))->toHaveKeys(['awareness', 'decision']);
});

test('a breakdown refuses an unknown dimension', function () {
    ['w' => $w] = metricsData();

    service()->breakdown(metricsFilter($w), 'color');
})->throws(InvalidArgumentException::class);

test('a prompt without a topic is grouped under an empty key', function () {
    ['w' => $w] = metricsData();
    Prompt::query()->whereKey($w->p2->id)->update(['topic_id' => null]);

    expect(service()->breakdown(metricsFilter($w), 'topic'))->toHaveKey('')
        ->and(service()->breakdown(metricsFilter($w), 'topic')['']->responses)->toBe(10);
});

test('stability is the mean share of repetitions that mention the brand per prompt, engine and round', function () {
    $w = MetricsWorld::create();
    $row = fn (string $date, int $responses, int $mentioned, ?int $promptId = null) => AggDaily::factory()->create([
        'market_id' => $w->market->id, 'engine_key' => $w->engine->key, 'prompt_id' => $promptId ?? $w->p1->id, 'date' => $date,
        'responses' => $responses, 'brand_mentioned' => $mentioned,
    ]);
    $row('2026-11-02', 2, 1);              // 0.5
    $row('2026-11-03', 3, 1);              // 0.333
    $row('2026-11-04', 3, 3);              // 1.0
    $row('2026-11-05', 2, 0);              // 0.0
    $row('2026-11-06', 1, 1);              // one repetition: nothing to compare, left out
    $row('2026-11-03', 3, 1, $w->p2->id);  // 0.333, another prompt

    $stability = service()->stability(metricsFilter($w));

    // (0.5 + 1/3 + 1 + 0 + 1/3) / 5
    expect($stability['mean']->value)->toEqualWithDelta((0.5 + 1 / 3 + 1 + 0 + 1 / 3) / 5, 1e-9)
        ->and($stability['mean']->n)->toBe(5)
        ->and($stability['distribution'])->toBe(['0.00' => 1, '0.33' => 2, '0.50' => 1, '1.00' => 1]);

    expect(service()->stability(metricsFilter($w, ['promptIds' => [$w->p2->id]]))['mean']->n)->toBe(1)
        ->and(service()->stability(metricsFilter($w, ['from' => '2026-12-01', 'to' => '2026-12-02']))['mean']->value)->toBeNull();
});

test('stability per prompt and engine is the mean of that prompt and engine only', function () {
    ['w' => $w, 'engine2' => $engine2] = metricsData();

    $cells = service()->stabilityByPromptEngine(metricsFilter($w));
    $cell = fn (int $promptId, string $engine) => collect($cells)->first(
        fn (array $row) => $row['prompt_id'] === $promptId && $row['engine_key'] === $engine,
    );

    expect($cells)->toHaveCount(3)
        ->and($cell($w->p1->id, $w->engine->key)['mean']->value)->toEqualWithDelta(0.5, 1e-9)
        ->and($cell($w->p1->id, $w->engine->key)['mean']->n)->toBe(1)
        ->and($cell($w->p1->id, $engine2->key)['mean']->value)->toEqualWithDelta(1.0, 1e-9)
        ->and($cell($w->p2->id, $w->engine->key)['mean']->value)->toEqualWithDelta(0.3, 1e-9);
});

/**
 * @return array<string, mixed>
 */
function sourceRows(MetricsWorld $w, Engine $engine2): array
{
    $row = fn (array $attributes) => AggSourceDaily::factory()->create(['market_id' => $w->market->id, ...$attributes]);
    $row(['date' => '2026-11-02', 'engine_key' => $w->engine->key, 'domain' => 'review.example', 'citations' => 6, 'responses_with_domain' => 4, 'gap_responses' => 2]);
    $row(['date' => '2026-11-03', 'engine_key' => $w->engine->key, 'domain' => 'review.example', 'citations' => 2, 'responses_with_domain' => 2, 'gap_responses' => 1]);
    $row(['date' => '2026-11-10', 'engine_key' => $engine2->key, 'domain' => 'news.example', 'citations' => 10, 'responses_with_domain' => 10, 'gap_responses' => 0]);
    $row(['date' => '2026-11-10', 'engine_key' => $engine2->key, 'domain' => 'forum.example', 'citations' => 1, 'responses_with_domain' => 1, 'gap_responses' => 0]);
    $row(['date' => '2026-11-03', 'engine_key' => $w->engine->key, 'domain' => 'wiki.example', 'citations' => 3, 'responses_with_domain' => 3, 'gap_responses' => 3]);

    return [];
}

test('source share is the share of answers that cite each domain, most cited first', function () {
    ['w' => $w, 'engine2' => $engine2] = metricsData();
    sourceRows($w, $engine2);

    $share = service()->sourceShare(metricsFilter($w));

    // 40 answers in all: review.example 6 of them, news.example 10, wiki.example 3, forum.example 1.
    expect(array_column($share, 'domain'))->toBe(['news.example', 'review.example', 'wiki.example', 'forum.example'])
        ->and($share[0])->toBe(['domain' => 'news.example', 'citations' => 10, 'responses_with_domain' => 10, 'share' => 0.25, 'n' => 40])
        ->and($share[1]['share'])->toBe(6 / 40)
        ->and($share[1]['citations'])->toBe(8);

    // Limited to engine 1 the answer count is the 20 answers of engine 1.
    $engine1 = service()->sourceShare(metricsFilter($w, ['engineKeys' => [$w->engine->key]]));
    expect(array_column($engine1, 'domain'))->toBe(['review.example', 'wiki.example'])
        ->and($engine1[0]['share'])->toBe(6 / 20);

    expect(service()->sourceShare(metricsFilter($w), 2))->toHaveCount(2);
});

test('source gap is the domains cited where competitors are named and the brand is not', function () {
    ['w' => $w, 'engine2' => $engine2] = metricsData();
    sourceRows($w, $engine2);

    $gap = service()->sourceGap(metricsFilter($w));

    // 6 answers name competitors but not the brand (4 + 2 + 0). wiki.example is in 3 of them, review.example in 3.
    expect($gap)->toBe([
        ['domain' => 'review.example', 'gap_responses' => 3, 'gap_rate' => 0.5, 'n' => 6],
        ['domain' => 'wiki.example', 'gap_responses' => 3, 'gap_rate' => 0.5, 'n' => 6],
    ]);
});

test('source figures cannot be limited by a prompt dimension', function () {
    ['w' => $w, 'topic1' => $topic1] = metricsData();

    expect(fn () => service()->sourceShare(metricsFilter($w, ['topicIds' => [$topic1->id]])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => service()->sourceGap(metricsFilter($w, ['promptIds' => [$w->p1->id]])))->toThrow(InvalidArgumentException::class);
});

test('source share with no answers is null, not a division by zero', function () {
    ['w' => $w, 'engine2' => $engine2] = metricsData();
    sourceRows($w, $engine2);

    $share = service()->sourceShare(metricsFilter($w, ['from' => '2026-11-03', 'to' => '2026-11-03', 'engineKeys' => [$engine2->key]]));
    expect($share)->toBe([]);

    AggDaily::query()->update(['responses' => 0]);
    expect(service()->sourceShare(metricsFilter($w))[0]['share'])->toBeNull();
});

test('AI bot hits and AI traffic are summed per bot and source for the period', function () {
    $w = MetricsWorld::create();
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'GPTBot', 'date' => '2026-11-02', 'hits' => 8]);
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'GPTBot', 'date' => '2026-11-03', 'hits' => 4]);
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'ClaudeBot', 'date' => '2026-11-03', 'hits' => 20]);
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'GPTBot', 'date' => '2026-12-03', 'hits' => 99]);
    AiReferralDaily::factory()->create(['brand_id' => $w->brand->id, 'source' => 'chatgpt.com', 'date' => '2026-11-02', 'sessions' => 10, 'engaged_sessions' => 6, 'conversions' => '1.5000']);
    AiReferralDaily::factory()->create(['brand_id' => $w->brand->id, 'source' => 'chatgpt.com', 'date' => '2026-11-03', 'sessions' => 5, 'engaged_sessions' => 1, 'conversions' => '0.5000']);
    AiReferralDaily::factory()->create(['brand_id' => $w->brand->id, 'source' => 'perplexity.ai', 'date' => '2026-11-03', 'sessions' => 2, 'engaged_sessions' => 2, 'conversions' => '0.0000']);

    expect(service()->botHits(metricsFilter($w)))->toBe([['bot' => 'ClaudeBot', 'hits' => 20], ['bot' => 'GPTBot', 'hits' => 12]])
        ->and(service()->aiTraffic(metricsFilter($w)))->toBe([
            ['source' => 'chatgpt.com', 'sessions' => 15, 'engaged_sessions' => 7, 'conversions' => 2.0],
            ['source' => 'perplexity.ai', 'sessions' => 2, 'engaged_sessions' => 2, 'conversions' => 0.0],
        ])
        ->and(service()->botHits(metricsFilter($w, ['from' => '2026-11-03', 'to' => '2026-11-03'])))->toHaveCount(2)
        ->and(service()->aiTraffic(metricsFilter($w, ['from' => '2027-01-01', 'to' => '2027-01-02'])))->toBe([]);
});

test('model change markers are listed for the period and engines', function () {
    $w = MetricsWorld::create();
    $engine2 = Engine::factory()->create();
    ModelChange::factory()->create(['engine_key' => $w->engine->key, 'changed_on' => '2026-11-05', 'from_model' => 'a', 'to_model' => 'b']);
    ModelChange::factory()->create(['engine_key' => $engine2->key, 'changed_on' => '2026-11-03', 'from_model' => 'x', 'to_model' => 'y']);
    ModelChange::factory()->create(['engine_key' => $w->engine->key, 'changed_on' => '2026-12-05']);

    $all = service()->modelChanges('2026-11-01', '2026-11-30');
    expect($all->map(fn ($m) => [$m->engine_key, $m->to_model])->all())->toBe([[$engine2->key, 'y'], [$w->engine->key, 'b']])
        ->and(service()->modelChanges('2026-11-01', '2026-11-30', [$w->engine->key])->pluck('to_model')->all())->toBe(['b']);
});

test('share of voice is split across the brand and each active competitor', function () {
    ['w' => $w] = metricsData();

    $parts = service()->voiceParts(metricsFilter($w));

    expect($parts)->toHaveCount(3)
        ->and($parts[0]['mentions'])->toBe(28)
        ->and($parts[0]['share'])->toBe(28 / 67)
        ->and($parts[0]['n'])->toBe(40)
        ->and($parts[1]['name'])->toBe('Nordlys')
        ->and($parts[1]['mentions'])->toBe(14)
        ->and($parts[2]['name'])->toBe('Solbaer')
        ->and($parts[2]['mentions'])->toBe(25);
});

test('voice parts use a fixed number of queries as active competitors grow', function () {
    ['w' => $w] = metricsData();

    foreach ([0, 8] as $additionalCompetitors) {
        Competitor::factory()->count($additionalCompetitors)->for($w->brand)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $parts = service()->voiceParts(metricsFilter($w));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        expect($queries)->toHaveCount(2)
            ->and($parts)->toHaveCount(3 + $additionalCompetitors)
            ->and($parts[0]['mentions'])->toBe(28)
            ->and($parts[0]['share'])->toBe(28 / 67)
            ->and($parts[0]['n'])->toBe(40)
            ->and(array_sum(array_column($parts, 'mentions')))->toBe(67);
    }
});

test('voice parts apply the same filters to the brand and competitors', function () {
    ['w' => $w, 'topic2' => $topic2] = metricsData();

    $parts = service()->voiceParts(metricsFilter($w, [
        'from' => '2026-11-03', 'to' => '2026-11-03',
        'marketIds' => [$w->market->id], 'engineKeys' => [$w->engine->key],
        'promptIds' => [$w->p2->id], 'topicIds' => [$topic2->id],
        'intents' => ['comparison'], 'funnelStages' => ['decision'],
    ]));

    expect(array_column($parts, 'mentions'))->toBe([3, 9, 0])
        ->and(array_column($parts, 'share'))->toBe([3 / 12, 9 / 12, 0.0])
        ->and(array_column($parts, 'n'))->toBe([10, 10, 10]);
});

test('voice parts retain zero competitors and handle empty daily data and non-object JSON', function () {
    ['w' => $w] = metricsData();
    $empty = service()->voiceParts(metricsFilter($w, ['from' => '2027-01-01', 'to' => '2027-01-02']));
    expect(array_column($empty, 'mentions'))->toBe([0, 0, 0])
        ->and(array_column($empty, 'share'))->toBe([null, null, null])
        ->and(array_column($empty, 'n'))->toBe([0, 0, 0]);

    AggDaily::query()->update(['competitor_mentions' => '[]']);
    $parts = service()->voiceParts(metricsFilter($w));
    expect(array_column($parts, 'mentions'))->toBe([28, 0, 0])
        ->and(array_column($parts, 'share'))->toBe([1.0, 0.0, 0.0])
        ->and(array_column($parts, 'n'))->toBe([40, 40, 40]);

    Competitor::query()->where('brand_id', $w->brand->id)->update(['is_active' => false]);
    expect(service()->voiceParts(metricsFilter($w)))->toBe([
        ['id' => 'brand', 'name' => null, 'mentions' => 28, 'share' => 1.0, 'n' => 40],
    ]);
});

test('metrics only see the tenant in the context', function () {
    ['w' => $a] = metricsData();
    $b = MetricsWorld::create();
    AggDaily::factory()->create(['market_id' => $b->market->id, 'engine_key' => $b->engine->key, 'prompt_id' => $b->p1->id, 'date' => '2026-11-02', 'responses' => 100, 'brand_mentioned' => 100]);

    // Tenant B is in the context now: tenant A's brand has no data there.
    expect(service()->visibility(metricsFilter($a))->n)->toBe(0)
        ->and(service()->visibility(metricsFilter($b))->n)->toBe(100)
        ->and(service()->voiceParts(metricsFilter($a)))->toBe([
            ['id' => 'brand', 'name' => null, 'mentions' => 0, 'share' => null, 'n' => 0],
        ])
        ->and(service()->voiceParts(metricsFilter($b))[0]['mentions'])->toBe(100);
});

test('a filter that starts after it ends or has a bad date is refused', function () {
    expect(fn () => new MetricsFilter(1, '2026-11-10', '2026-11-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MetricsFilter(1, '10.11.2026', '2026-11-11'))->toThrow(InvalidArgumentException::class);
});
