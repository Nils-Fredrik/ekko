<?php

use App\Dashboard\SourcePage;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

// U21: the competitor setup API and the fixed color order (docs/ux-arbeidsflate.md 6.3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->tenant->owner->switchTeam($this->w->tenant);
    $this->c3 = Competitor::factory()->for($this->w->brand)->create(['name' => 'Aurora']);
    AggDaily::factory()->create([
        'market_id' => $this->w->market->id, 'engine_key' => $this->w->engine->key, 'prompt_id' => $this->w->p1->id,
        'date' => '2026-11-18', 'responses' => 20, 'brand_mentioned' => 10,
        'competitor_mentions' => [(string) $this->w->c1->id => 5, (string) $this->w->c2->id => 3, (string) $this->c3->id => 1],
    ]);
    Cache::flush();
});

test('a new competitor takes the next place, so the colors of the others stay', function () {
    expect(Competitor::colorIndexes($this->w->brand))->toBe([$this->w->c1->id => 0, $this->w->c2->id => 1, $this->c3->id => 2]);
});

test('a changed order gives the same color place on every page that shows competitors', function () {
    $w = $this->w;
    $order = [$this->c3->id, $w->c1->id, $w->c2->id];

    $this->actingAs($w->tenant->owner)->postJson(route('brands.competitors.reorder', $w->brand), ['ids' => $order])
        ->assertOk()->assertJsonPath('color_indexes', [(string) $this->c3->id => 0, (string) $w->c1->id => 1, (string) $w->c2->id => 2]);
    $expected = [$this->c3->id => 0, $w->c1->id => 1, $w->c2->id => 2];
    expect(Competitor::colorIndexes($w->brand))->toBe($expected);

    // Competitor page: the list and the table.
    $this->actingAs($w->tenant->owner)->get(route('brands.competitors.index', [$w->brand, 'days' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('competitors', fn ($items) => collect($items)->mapWithKeys(fn ($item) => [$item['id'] => $item['color_index']])->all() == $expected)
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.table', fn ($rows) => collect($rows)->reject(fn ($row) => $row['own'])
                    ->mapWithKeys(fn ($row) => [$row['id'] => $row['color_index']])->all() == $expected)));

    // Sources page.
    $filter = new MetricsFilter($w->brand->id, '2026-11-12', '2026-11-18');
    expect(collect(app(SourcePage::class)->chartData($w->brand, $filter)['competitors'])->mapWithKeys(fn ($c) => [$c['id'] => $c['color_index']])->all())
        ->toBe($expected);

    // Dashboard and reports read the competitors in the order of the series.
    $series = app(MetricsService::class)->competitorVisibilitySeries($filter);
    expect(array_values(array_filter(array_column($series[0]['entities'], 'id'), fn ($id) => $id !== 'brand')))
        ->toBe(array_map('strval', $order));

    // API.
    expect($this->c3->fresh()->position)->toBeLessThan($w->c1->fresh()->position);
});

test('a new order is not served from the graph cache', function () {
    $w = $this->w;
    $table = function () use ($w): array {
        $rows = [];
        $this->actingAs($w->tenant->owner)->get(route('brands.competitors.index', [$w->brand, 'days' => 7]))
            ->assertInertia(function ($page) use (&$rows) {
                $page->loadDeferredProps(function ($deferred) use (&$rows) {
                    $rows = collect($deferred->toArray()['props']['chartData']['table'])->reject(fn ($row) => $row['own'])
                        ->mapWithKeys(fn ($row) => [$row['id'] => $row['color_index']])->all();
                });
            });

        return $rows;
    };

    expect($table())->toEqual([$w->c1->id => 0, $w->c2->id => 1, $this->c3->id => 2]);
    $this->actingAs($w->tenant->owner)->postJson(route('brands.competitors.reorder', $w->brand), ['ids' => [$w->c2->id, $w->c1->id, $this->c3->id]])->assertOk();

    expect($table())->toEqual([$w->c2->id => 0, $w->c1->id => 1, $this->c3->id => 2]);
});

test('the order must name every active competitor once, and an inactive competitor keeps its place', function () {
    $w = $this->w;
    $w->c2->forceFill(['is_active' => false])->save();
    $place = $w->c2->fresh()->position;

    $this->actingAs($w->tenant->owner)->postJson(route('brands.competitors.reorder', $w->brand), ['ids' => [$w->c1->id]])->assertStatus(422);
    $this->actingAs($w->tenant->owner)->postJson(route('brands.competitors.reorder', $w->brand), ['ids' => [$w->c1->id, $w->c2->id, $this->c3->id]])->assertStatus(422);
    $this->actingAs($w->tenant->owner)->postJson(route('brands.competitors.reorder', $w->brand), ['ids' => [$this->c3->id, $w->c1->id]])->assertOk();

    expect($w->c2->fresh()->position)->toBe($place)
        ->and(Competitor::colorIndexes($w->brand))->toBe([$this->c3->id => 0, $w->c1->id => 1]);
});

test('name and active state can be changed, but not to a name the brand already knows', function () {
    $w = $this->w;

    $this->actingAs($w->tenant->owner)->patchJson(route('brands.competitors.update', [$w->brand, $w->c1->id]), ['name' => 'Nordlys Kaffe', 'is_active' => false])
        ->assertOk()->assertJsonPath('competitor.name', 'Nordlys Kaffe')->assertJsonPath('competitor.color_index', null);
    $this->actingAs($w->tenant->owner)->patchJson(route('brands.competitors.update', [$w->brand, $w->c1->id]), ['name' => 'Solbær AS'])
        ->assertOk();  // «Solbær» is not «Solbaer»
    $this->actingAs($w->tenant->owner)->patchJson(route('brands.competitors.update', [$w->brand, $w->c1->id]), ['name' => 'Solbaer AS'])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

test('aliases are added with a match mode, regex is guarded, and they can be changed and removed', function () {
    $w = $this->w;
    $url = route('brands.competitors.aliases.store', [$w->brand, $w->c1->id]);

    $this->actingAs($w->tenant->owner)->postJson($url, ['alias' => 'Nordlys Norge', 'match_mode' => 'exact_word'])->assertCreated()
        ->assertJsonPath('competitor.aliases.0.alias', 'Nordlys Norge');
    $this->actingAs($w->tenant->owner)->postJson($url, ['alias' => 'Nordlys Norge', 'match_mode' => 'exact_word'])->assertStatus(422);
    $this->actingAs($w->tenant->owner)->postJson($url, ['alias' => '(a+)+', 'match_mode' => 'regex'])->assertStatus(422)->assertJsonValidationErrors('alias');
    $this->actingAs($w->tenant->owner)->postJson($url, ['alias' => 'Nordlys\s+AS', 'match_mode' => 'regex'])->assertCreated();

    $alias = CompetitorAlias::query()->where('alias', 'Nordlys Norge')->sole();
    $this->actingAs($w->tenant->owner)->patchJson(route('brands.competitors.aliases.update', [$w->brand, $w->c1->id, $alias->id]), ['match_mode' => 'case_insensitive'])
        ->assertOk();
    expect($alias->fresh()->match_mode)->toBe('case_insensitive');

    // An alias of another competitor is not found through this one.
    $other = CompetitorAlias::factory()->create(['competitor_id' => $w->c2->id, 'alias' => 'Solbaer Norge', 'match_mode' => 'exact_word']);
    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.competitors.aliases.destroy', [$w->brand, $w->c1->id, $other->id]))->assertNotFound();
    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.competitors.aliases.destroy', [$w->brand, $w->c1->id, $alias->id]))->assertOk();
    expect(CompetitorAlias::query()->whereKey($alias->id)->exists())->toBeFalse()
        ->and(CompetitorAlias::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('domains are normalized and unique per competitor', function () {
    $w = $this->w;
    $url = route('brands.competitors.domains.store', [$w->brand, $w->c1->id]);

    $this->actingAs($w->tenant->owner)->postJson($url, ['domain' => ' WWW.Nordlys.NO '])->assertCreated()->assertJsonPath('competitor.domains.0.domain', 'nordlys.no');
    $this->actingAs($w->tenant->owner)->postJson($url, ['domain' => 'nordlys.no'])->assertStatus(422);
    $this->actingAs($w->tenant->owner)->postJson($url, ['domain' => 'ikke et domene'])->assertStatus(422);

    $domain = CompetitorDomain::query()->sole();
    $this->actingAs($w->tenant->owner)->deleteJson(route('brands.competitors.domains.destroy', [$w->brand, $w->c1->id, $domain->id]))->assertOk();
    expect(CompetitorDomain::query()->count())->toBe(0);
});

test('a competitor of another brand cannot be changed through this brand', function () {
    $w = $this->w;
    $otherBrand = Brand::factory()->create();
    $foreign = Competitor::factory()->for($otherBrand)->create();

    $this->actingAs($w->tenant->owner)->patchJson(route('brands.competitors.update', [$w->brand, $foreign->id]), ['is_active' => false])->assertNotFound();
    $this->actingAs($w->tenant->owner)->postJson(route('brands.competitors.reorder', $w->brand), ['ids' => [$foreign->id, $w->c1->id, $w->c2->id, $this->c3->id]])->assertStatus(422);
    expect($foreign->fresh()->is_active)->toBeTrue();
});
