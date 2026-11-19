<?php

// T43, docs/spec.md 11.3: every number in an action's evidence comes from the database. "Today" is Monday
// 2026-11-16 in Oslo, so the 30-day window is 2026-10-17 to 2026-11-15, the 14-day window starts 2026-11-02 and
// the 7-day window 2026-11-09.

use App\ActionEngine\ActionGenerator;
use App\ActionEngine\ActionTemplates;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Metrics\DailyAggregator;
use App\Models\Action;
use App\Models\AlertRule;
use App\Models\BotHitDaily;
use App\Models\Prompt;
use App\Models\Topic;
use Carbon\CarbonImmutable;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->market->forceFill(['language' => 'nb', 'country' => 'NO'])->save();
});

function generateActions(MetricsWorld $w): void
{
    app(ActionGenerator::class)->generate($w->brand->fresh(), CarbonImmutable::now());
}

function aggregateDays(string ...$dates): void
{
    foreach ($dates as $date) {
        app(DailyAggregator::class)->aggregate($date);
    }
}

/**
 * $count answers on $prompt on the day, each naming the brand and citing an own page.
 */
function brandAnswers(MetricsWorld $w, Prompt $prompt, string $day, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $w->answer($prompt, "{$day} 10:00:00", ['brand' => ['pos' => 1], 'citations' => [['fjordkaffe.no', 'own']]]);
    }
}

function actionsOf(ActionType $type): array
{
    return Action::query()->where('type', $type->value)->orderBy('id')->get()->all();
}

// source_gap ---------------------------------------------------------------------------------------------------

test('a source gap becomes an action with the counts, competitors and prompts from the answers', function () {
    $w = $this->w;
    brandAnswers($w, $w->p2, '2026-11-10', 25);
    // 3 answers cite the domain and name the brand: not a gap.
    for ($i = 0; $i < 3; $i++) {
        $w->answer($w->p1, '2026-11-10 10:00:00', ['brand' => ['pos' => 2], 'citations' => [['bransjeside.no', 'third_party']]]);
    }
    // 5 gap answers: Nordlys in all of them, Solbaer in 2, the brand in none.
    for ($i = 0; $i < 5; $i++) {
        $w->answer($w->p1, '2026-11-11 10:00:00', [
            'competitors' => $i < 2 ? [$w->c1->id => 1, $w->c2->id => 2] : [$w->c1->id => 1],
            'citations' => [['bransjeside.no', 'third_party']],
        ]);
    }
    // A competitor-only answer that does not cite the domain: in the market's n, not in the domain's gap.
    $w->answer($w->p2, '2026-11-11 12:00:00', ['competitors' => [$w->c2->id => 1]]);
    aggregateDays('2026-11-10', '2026-11-11');

    generateActions($w);

    $actions = actionsOf(ActionType::SourceGap);
    expect($actions)->toHaveCount(1);
    $action = $actions[0];
    expect($action->status)->toBe(ActionStatus::New)
        ->and($action->market_id)->toBe($w->market->id)
        ->and($action->created_by)->toBe('system')
        ->and($action->fingerprint)->toHaveLength(64)
        ->and($action->evidence['domain'])->toBe('bransjeside.no')
        ->and($action->evidence['gap_responses'])->toBe(5)
        ->and($action->evidence['responses_with_domain'])->toBe(8)
        ->and($action->evidence['market_competitor_only_responses'])->toBe(6)
        ->and($action->evidence['responses'])->toBe(34)
        ->and($action->evidence['period'])->toEqual(['from' => '2026-10-17', 'to' => '2026-11-15', 'days' => 30])
        ->and($action->competitor_evidence)->toEqual([
            ['competitor_id' => $w->c1->id, 'name' => 'Nordlys', 'answers' => 5, 'of' => 5],
            ['competitor_id' => $w->c2->id, 'name' => 'Solbaer', 'answers' => 2, 'of' => 5],
        ])
        ->and($action->prompts->pluck('id')->all())->toBe([$w->p1->id])
        ->and($action->engine_keys)->toBe([$w->engine->key])
        // 8 citing answers a month × 5 of 8 competitor-only = 5.00, level 3.
        ->and($action->impact_score)->toBe('5.00')
        ->and($action->impact_level)->toBe(3)
        ->and($action->text_source)->toBe('template')
        ->and($action->title)->toBe('Bli omtalt på bransjeside.no')
        ->and(ActionTemplates::evidenceSentence($action, 'nb'))
        ->toBe('bransjeside.no ble sitert i 5 svar der konkurrenter ble nevnt og dere ikke, de siste 30 dagene. Nordlys ble nevnt i 5 av dem.');
});

test('a gap that falls outside the largest per market still supports its action', function () {
    $w = $this->w;
    config(['ekko.actions.source_gap.max_per_market' => 1, 'ekko.actions.source_gap.min_gap_responses' => 2]);
    brandAnswers($w, $w->p2, '2026-11-01', 30);
    $gap = fn (string $domain, int $count, string $day) => collect(range(1, $count))->each(fn () => $w->answer($w->p1, "{$day} 10:00:00", [
        'competitors' => [$w->c1->id => 1], 'citations' => [[$domain, 'third_party']],
    ]));
    $gap('a.no', 3, '2026-11-02');
    aggregateDays('2026-11-01', '2026-11-02');
    generateActions($w);
    $first = Action::query()->sole();

    // b.no overtakes a.no; a.no stays over the threshold.
    $gap('b.no', 6, '2026-11-17');
    foreach ([7, 14] as $days) {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo')->addDays($days));
        aggregateDays('2026-11-17');
        generateActions($w);
    }

    expect($first->refresh()->status)->toBe(ActionStatus::New)->and($first->unsupported_generations)->toBe(0)
        ->and(Action::query()->count())->toBe(2);
});

test('a source gap below the minimum, or a market with too few answers, gives no action', function () {
    $w = $this->w;
    brandAnswers($w, $w->p2, '2026-11-10', 30);
    for ($i = 0; $i < 2; $i++) {
        $w->answer($w->p1, '2026-11-11 10:00:00', ['competitors' => [$w->c1->id => 1], 'citations' => [['bransjeside.no', 'third_party']]]);
    }
    aggregateDays('2026-11-10', '2026-11-11');
    generateActions($w);
    expect(actionsOf(ActionType::SourceGap))->toBe([]);

    config(['ekko.actions.min_answers' => 100, 'ekko.actions.source_gap.min_gap_responses' => 1]);
    app(ActionGenerator::class)->generate($w->brand->fresh(), CarbonImmutable::now(), force: true);
    expect(actionsOf(ActionType::SourceGap))->toBe([]);
});

// owned_content ------------------------------------------------------------------------------------------------

test('a topic whose prompts have low visibility and no own citations becomes an owned_content action', function () {
    $w = $this->w;
    $topic = Topic::factory()->for($w->brand)->create(['name' => 'Shopify-migrering']);
    $prompts = Prompt::factory()->count(3)->for($w->market)->create(['topic_id' => $topic->id]);
    // Two prompts: 4 answers each, the brand in 1, a competitor in the other 3, no own citation.
    foreach ([$prompts[0], $prompts[1]] as $prompt) {
        $w->answer($prompt, '2026-11-12 10:00:00', ['brand' => ['pos' => 3]]);
        for ($i = 0; $i < 3; $i++) {
            $w->answer($prompt, '2026-11-12 10:00:00', ['competitors' => [$w->c1->id => 1]]);
        }
    }
    // The third prompt is cited on the brand's own pages: not low.
    brandAnswers($w, $prompts[2], '2026-11-12', 4);
    brandAnswers($w, $w->p2, '2026-11-12', 20);
    aggregateDays('2026-11-12');

    generateActions($w);

    $actions = actionsOf(ActionType::OwnedContent);
    expect($actions)->toHaveCount(1);
    $action = $actions[0];
    expect($action->topic_id)->toBe($topic->id)
        ->and($action->evidence['topic'])->toBe('Shopify-migrering')
        ->and($action->evidence['prompts_total'])->toBe(3)
        ->and($action->evidence['prompts_without_own_citation'])->toBe(2)
        ->and($action->evidence['responses'])->toBe(8)
        ->and($action->evidence['competitor_only_responses'])->toBe(6)
        ->and($action->evidence['visibility'])->toEqual(['value' => 0.25, 'n' => 8, 'low_sample' => true])
        ->and($action->competitor_evidence)->toEqual([['competitor_id' => $w->c1->id, 'name' => 'Nordlys', 'answers' => 6, 'of' => 8]])
        ->and($action->prompts->pluck('id')->sort()->values()->all())->toBe([$prompts[0]->id, $prompts[1]->id])
        // 8 answers a month × 6 of 8 = 6.00, level 3.
        ->and($action->impact_score)->toBe('6.00')
        ->and(ActionTemplates::evidenceSentence($action, 'nb'))
        ->toBe('Ingen egne sider siteres på 2 av 3 prompts om temaet «Shopify-migrering». Synligheten der er 25 %.');
});

// new_competitor -----------------------------------------------------------------------------------------------

test('an unknown brand over the alert threshold becomes a new_competitor action, unless it is already a competitor', function () {
    $w = $this->w;
    AlertRule::factory()->create(['brand_id' => $w->brand->id, 'type' => 'new_competitor', 'threshold' => '20']);
    // 30 answers in the 7-day window, 9 name "Kaffebrenneriet" (30 %), 3 of those also the brand.
    for ($i = 0; $i < 30; $i++) {
        $w->answer($i % 2 === 0 ? $w->p1 : $w->p2, '2026-11-13 10:00:00', [
            ...($i < 9 ? ['unknown' => 'Kaffebrenneriet'] : []),
            ...($i < 3 || $i >= 9 ? ['brand' => ['pos' => 1]] : []),
        ]);
    }
    // "Solbaer" is a registered competitor although the extraction did not place it.
    for ($i = 0; $i < 9; $i++) {
        $w->answer($w->p2, '2026-11-13 11:00:00', ['unknown' => 'solbaer', 'brand' => ['pos' => 1]]);
    }
    aggregateDays('2026-11-13');

    generateActions($w);

    $actions = actionsOf(ActionType::NewCompetitor);
    expect($actions)->toHaveCount(1);
    $action = $actions[0];
    expect($action->evidence['name'])->toBe('Kaffebrenneriet')
        ->and($action->evidence['answers'])->toBe(9)
        ->and($action->evidence['answers_without_brand'])->toBe(6)
        ->and($action->evidence['responses'])->toBe(39)
        ->and($action->evidence['threshold_percent'])->toEqual(20)
        ->and($action->evidence['period']['days'])->toBe(7)
        ->and(array_sum($action->prompts->map(fn ($p) => 1)->all()))->toBe(2)
        ->and(ActionTemplates::evidenceSentence($action, 'nb'))
        ->toBe('Kaffebrenneriet nevnes i 23 % av svarene i NO de siste 7 dagene, men er ikke registrert som konkurrent.');
});

// crawler_access -----------------------------------------------------------------------------------------------

test('a search bot without hits while other bots fetch the pages becomes a brand-level crawler_access action', function () {
    $w = $this->w;
    $w->brand->forceFill(['primary_domain' => 'fjordkaffe.no'])->save();
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-14', 'hits' => 40]);
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'claude_searchbot', 'date' => '2026-11-10', 'hits' => 7]);
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'perplexitybot', 'date' => '2026-11-12', 'hits' => 5]);
    // OAI-SearchBot was last seen before the 14-day window.
    BotHitDaily::factory()->create(['brand_id' => $w->brand->id, 'bot' => 'oai_searchbot', 'date' => '2026-10-20', 'hits' => 3]);

    generateActions($w);

    $actions = actionsOf(ActionType::CrawlerAccess);
    expect($actions)->toHaveCount(1);
    $action = $actions[0];
    expect($action->market_id)->toBeNull()
        ->and($action->evidence['bot'])->toBe('oai_searchbot')
        ->and($action->evidence['user_agent'])->toBe('OAI-SearchBot')
        ->and($action->evidence['hits'])->toBe(0)
        ->and($action->evidence['other_bot_hits'])->toBe(52)
        ->and($action->evidence['last_hit_on'])->toBe('2026-10-20')
        ->and($action->evidence['period'])->toEqual(['from' => '2026-11-02', 'to' => '2026-11-15', 'days' => 14])
        ->and(ActionTemplates::evidenceSentence($action, 'nb'))
        ->toBe('OAI-SearchBot har ikke hentet noen sider de siste 14 dagene. Andre AI-roboter gjorde 52 forespørsler i samme periode.');
});

test('without bot data the crawler source does not judge the brand', function () {
    generateActions($this->w);

    expect(actionsOf(ActionType::CrawlerAccess))->toBe([]);
});
