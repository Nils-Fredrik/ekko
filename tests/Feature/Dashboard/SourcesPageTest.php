<?php

use App\Enums\ActionStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Action;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Brand;
use App\Models\BrandDomain;
use App\Models\CompetitorDomain;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

// U08: the sources page (docs/ui-forbedring.md 7.5).

function sourcesWorld(): MetricsWorld
{
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'prompt_id' => $world->p1->id,
        'date' => $today, 'responses' => 20, 'competitor_only_responses' => 10,
    ]);
    foreach ([
        ['fjordkaffe.example.test', 8, 0, ['own' => ['other' => 8]]],
        ['nordlys.example.test', 6, 0, ['competitor' => ['other' => 6]]],
        ['avis.example.test', 4, 3, ['third_party' => ['news' => 3, 'review' => 1]]],
        ['forum.example.test', 2, 1, ['third_party' => ['forum' => 2]]],
    ] as [$domain, $responses, $gap, $distribution]) {
        AggSourceDaily::factory()->create([
            'market_id' => $world->market->id, 'brand_id' => $world->brand->id, 'engine_key' => $world->engine->key,
            'domain' => $domain, 'date' => $today, 'citations' => $responses, 'responses_with_domain' => $responses,
            'gap_responses' => $gap, 'citation_distribution' => $distribution,
        ]);
    }
    BrandDomain::factory()->create(['brand_id' => $world->brand->id, 'domain' => 'fjordkaffe.example.test']);
    CompetitorDomain::factory()->for($world->c1)->create(['domain' => 'nordlys.example.test']);
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    return $world;
}

test('domains are ranked with their ownership, the competitor color and their source types', function () {
    $world = sourcesWorld();

    $this->actingAs($world->tenant->owner)->get(route('brands.sources', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Sources')
            ->where('citing', null)
            ->missing('chartData')
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.sources.0.domain', 'fjordkaffe.example.test')
                ->where('chartData.sources.0.ownership', 'own')
                ->where('chartData.sources.0.share', 0.4)
                ->where('chartData.sources.1.ownership', 'competitor')
                ->where('chartData.sources.1.competitor_id', $world->c1->id)
                ->where('chartData.sources.2.ownership', 'third_party')
                ->where('chartData.sources.2.types', ['news', 'review'])
                ->where('chartData.competitors', [
                    ['id' => $world->c1->id, 'name' => 'Nordlys', 'color_index' => 0],
                    ['id' => $world->c2->id, 'name' => 'Solbaer', 'color_index' => 1],
                ])
                ->where('chartData.types.complete', true)
                ->where('chartData.types.items', [
                    ['key' => 'other', 'value' => 14],
                    ['key' => 'news', 'value' => 3],
                    ['key' => 'forum', 'value' => 2],
                    ['key' => 'review', 'value' => 1],
                ])));
});

test('source types are not shown as complete while a day has no distribution yet', function () {
    $world = sourcesWorld();
    AggSourceDaily::factory()->create([
        'market_id' => $world->market->id, 'brand_id' => $world->brand->id, 'engine_key' => $world->engine->key,
        'domain' => 'old.example.test', 'date' => CarbonImmutable::now('Europe/Oslo')->subDay()->toDateString(),
        'citation_distribution' => null,
    ]);

    $this->actingAs($world->tenant->owner)->get(route('brands.sources', $world->brand))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.types', ['complete' => false, 'items' => []])));
});

test('a source gap compares the brand and competitor answers and links to its open action', function () {
    $world = sourcesWorld();
    $now = now()->subHour()->toIso8601String();
    // Two answers name the brand, one of them cites avis; three name only a competitor, two of them cite avis.
    $world->answer($world->p1, $now, ['brand' => [], 'citations' => [['avis.example.test', 'third_party']]]);
    $world->answer($world->p1, $now, ['brand' => []]);
    $world->answer($world->p1, $now, ['competitors' => [$world->c1->id => 1], 'citations' => [['avis.example.test', 'third_party']]]);
    $world->answer($world->p2, $now, ['competitors' => [$world->c2->id => 2], 'citations' => [['avis.example.test', 'third_party']]]);
    $world->answer($world->p2, $now, ['competitors' => [$world->c2->id => 1]]);

    Action::factory()->create(['brand_id' => $world->brand->id, 'market_id' => $world->market->id, 'status' => ActionStatus::Expired, 'evidence' => ['domain' => 'avis.example.test'], 'impact_level' => 5]);
    Action::factory()->create(['brand_id' => $world->brand->id, 'market_id' => $world->market->id, 'status' => ActionStatus::Done, 'evidence' => ['domain' => 'avis.example.test'], 'impact_level' => 5]);
    $open = Action::factory()->create(['brand_id' => $world->brand->id, 'market_id' => $world->market->id, 'status' => ActionStatus::Planned, 'evidence' => ['domain' => 'avis.example.test'], 'impact_level' => 1]);
    $other = Brand::factory()->create();
    Action::factory()->create(['brand_id' => $other->id, 'evidence' => ['domain' => 'forum.example.test']]);

    $this->actingAs($world->tenant->owner)->get(route('brands.sources', $world->brand))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->has('chartData.gaps', 2)
            ->where('chartData.gaps.0.domain', 'avis.example.test')
            ->where('chartData.gaps.0.gap_responses', 3)
            ->where('chartData.gaps.0.gap_rate', 0.3)
            ->where('chartData.gaps.0.own', ['value' => 0.5, 'n' => 2, 'low_sample' => true])
            ->where('chartData.gaps.0.competitors', ['value' => 2 / 3, 'n' => 3, 'low_sample' => true])
            ->where('chartData.gaps.0.action.id', $open->id)
            ->where('chartData.gaps.0.action.status', 'planned')
            ->where('chartData.gaps.0.action.href', route('brands.actions.show', [$world->brand, $open]))
            ->where('chartData.gaps.1.domain', 'forum.example.test')
            ->where('chartData.gaps.1.action', null)));
});

test('clicking a domain lists the answers that cite it, only for this brand and period', function () {
    $world = sourcesWorld();
    $citing = $world->answer($world->p1, now()->subHour()->toIso8601String(), [
        'brand' => [], 'citations' => [['avis.example.test', 'third_party'], ['forum.example.test', 'third_party']],
    ]);
    $world->answer($world->p2, now()->subMinutes(30)->toIso8601String(), ['citations' => [['forum.example.test', 'third_party']]]);
    $world->answer($world->p1, now()->subDays(40)->toIso8601String(), ['citations' => [['avis.example.test', 'third_party']]]);
    $world->answer($world->p1, now()->subHour()->toIso8601String(), ['status' => 'failed', 'citations' => [['avis.example.test', 'third_party']]]);
    // The same domain cited for another brand of the tenant is not shown.
    $otherBrand = Brand::factory()->create();
    $otherPrompt = Prompt::factory()->for(Market::factory()->for($otherBrand))->create();
    $world->answer($otherPrompt, now()->subHour()->toIso8601String(), ['citations' => [['avis.example.test', 'third_party']]]);

    $this->actingAs($world->tenant->owner)->get(route('brands.sources', [$world->brand, 'domain' => 'Avis.example.test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.domain', 'avis.example.test')
            ->where('citing.domain', 'avis.example.test')
            ->where('citing.total', 1)
            ->has('citing.answers', 1)
            ->where('citing.answers.0.id', $citing->id)
            ->where('citing.answers.0.brand_mentioned', true)
            ->where('citing.answers.0.urls', ['https://avis.example.test/x'])
            ->where('citing.answers.0.prompt.href', route('brands.prompts.show', [$world->brand, $world->p1])));

    // The partial reload the page makes on a click returns only the answers.
    $version = app(HandleInertiaRequests::class)->version(request());
    $this->actingAs($world->tenant->owner)
        ->withHeaders([
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Brands/Sources', 'X-Inertia-Partial-Data' => 'citing,filters',
        ])
        ->get(route('brands.sources', [$world->brand, 'domain' => 'forum.example.test']))
        ->assertOk()
        ->assertJsonPath('props.citing.total', 2)
        ->assertJsonPath('props.citing.answers.1.id', $citing->id)
        ->assertJsonMissingPath('props.chartData')
        ->assertJsonMissingPath('props.markets');
});

test('another tenant cannot read the answers that cite a domain', function () {
    $world = sourcesWorld();
    $world->answer($world->p1, now()->subHour()->toIso8601String(), ['citations' => [['avis.example.test', 'third_party']]]);
    $other = Team::factory()->tenant()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => Brand::factory()->create());
    $other->owner->switchTeam($other);

    $this->actingAs($other->owner)->get(route('brands.sources', [$world->brand, 'domain' => 'avis.example.test']))->assertNotFound();
    $this->actingAs($other->owner)->get(route('brands.sources', [$foreign, 'domain' => 'avis.example.test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('citing.total', 0)->where('citing.answers', []));
});
