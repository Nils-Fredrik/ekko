<?php

use App\Models\AggDaily;
use App\Models\Competitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

// U07: the competitors page (docs/ui-forbedring.md 7.4): rank over time, table and sentiment.

function competitorsWorld(?array $sentiment = ['own' => ['negative' => 1, 'neutral' => 3, 'positive' => 6]]): MetricsWorld
{
    $world = MetricsWorld::create();
    // Inactive and first by name: it must not take a color position from the active competitors.
    Competitor::factory()->for($world->brand)->create(['name' => 'Aurora', 'is_active' => false]);
    $c1 = (string) $world->c1->id;
    $c2 = (string) $world->c2->id;
    if ($sentiment !== null) {
        $sentiment['competitors'] = [
            $c1 => ['negative' => 4, 'neutral' => 0, 'positive' => 4],
            $c2 => ['negative' => 0, 'neutral' => 0, 'positive' => 0],
        ];
    }
    // Current period (seven days to 2026-11-19): Nordlys ahead of the brand, Solbaer last.
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'prompt_id' => $world->p1->id,
        'date' => '2026-11-18', 'responses' => 20, 'brand_mentioned' => 10,
        'competitor_mentions' => [$c1 => 14, $c2 => 4], 'sentiment_distribution' => $sentiment,
    ]);
    // Comparison period (2026-11-06 to 2026-11-12): the brand ahead of Nordlys.
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'prompt_id' => $world->p1->id,
        'date' => '2026-11-10', 'responses' => 10, 'brand_mentioned' => 6,
        'competitor_mentions' => [$c1 => 2, $c2 => 1], 'sentiment_distribution' => $sentiment,
    ]);
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    return $world;
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo')));

test('the table ranks the brand and active competitors with visibility, change, rank and sentiment', function () {
    $world = competitorsWorld();

    $this->actingAs($world->tenant->owner)->get(route('brands.competitors.index', [$world->brand, 'days' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Competitors')
            ->missing('chartData')
            // The list follows the color order (position, ADR 0043); Aurora was added last.
            ->where('competitors.0.name', 'Nordlys')
            ->where('competitors.0.color_index', 0)
            ->where('competitors.1.color_index', 1)
            ->where('competitors.2.name', 'Aurora')
            ->where('competitors.2.color_index', null)
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.grain', 'day')
                ->has('chartData.table', 3)
                ->where('chartData.table.0.key', (string) $world->c1->id)
                ->where('chartData.table.0.color_index', 0)
                ->where('chartData.table.0.visibility', ['value' => 0.7, 'n' => 20, 'low_sample' => false])
                ->where('chartData.table.0.previous_visibility', ['value' => 0.2, 'n' => 10, 'low_sample' => false])
                ->where('chartData.table.0.change', fn ($change) => abs($change - 0.5) < 1e-9)
                ->where('chartData.table.0.rank', 1)
                ->where('chartData.table.0.previous_rank', 2)
                ->where('chartData.table.0.sentiment', ['negative' => 0.5, 'neutral' => 0, 'positive' => 0.5, 'n' => 8, 'low_sample' => true])
                ->where('chartData.table.1.key', 'brand')
                ->where('chartData.table.1.own', true)
                ->where('chartData.table.1.color_index', null)
                ->where('chartData.table.1.change', fn ($change) => abs($change + 0.1) < 1e-9)
                ->where('chartData.table.1.rank', 2)
                ->where('chartData.table.1.previous_rank', 1)
                ->where('chartData.table.1.sentiment', ['negative' => 0.1, 'neutral' => 0.3, 'positive' => 0.6, 'n' => 10, 'low_sample' => false])
                ->where('chartData.table.2.key', (string) $world->c2->id)
                ->where('chartData.table.2.color_index', 1)
                ->where('chartData.table.2.rank', 3)
                ->where('chartData.table.2.sentiment', ['negative' => null, 'neutral' => null, 'positive' => null, 'n' => 0, 'low_sample' => true])
                ->where('chartData.sentiment.complete', true)
                ->where('chartData.ranking.0.entities', fn ($entities) => collect($entities)->pluck('rank', 'id')->all() === [
                    'brand' => 2, (string) $world->c1->id => 1, (string) $world->c2->id => 3,
                ])));
});

test('competitors have the same fixed color position as on the dashboard and the sources page', function () {
    $world = competitorsWorld();
    $owner = $world->tenant->owner;

    $positions = [(string) $world->c1->id => 0, (string) $world->c2->id => 1];
    $this->actingAs($owner)->get(route('brands.competitors.index', [$world->brand, 'days' => 7]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.table', fn ($rows) => collect($rows)->reject(fn ($row) => $row['own'])
                ->mapWithKeys(fn ($row) => [$row['key'] => $row['color_index']])->sortKeys()->all() == $positions)));

    $this->actingAs($owner)->get(route('brands.sources', [$world->brand, 'days' => 7]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.competitors', fn ($competitors) => collect($competitors)
                ->mapWithKeys(fn ($item) => [(string) $item['id'] => $item['color_index']])->all() == $positions)));

    $this->actingAs($owner)->get(route('brands.show', [$world->brand, 'days' => 7]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.competitor_colors', fn ($colors) => collect($colors)->all() == $positions)));
});

test('the rank chart is daily up to a month and weekly for longer periods', function (int $days, string $grain, string $first) {
    $world = competitorsWorld();

    $this->actingAs($world->tenant->owner)->get(route('brands.competitors.index', [$world->brand, 'days' => $days, 'grain' => 'month']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('filters.grain')
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.grain', $grain)
                ->where('chartData.ranking.0.period', $first)));
})->with([
    'four weeks' => [28, 'day', '2026-11-10'],
    'ninety days' => [90, 'week', '2026-11-09'],
]);

test('sentiment is left out of the table when the distribution is missing for a day', function () {
    $world = competitorsWorld(null);

    $this->actingAs($world->tenant->owner)->get(route('brands.competitors.index', [$world->brand, 'days' => 7]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.sentiment', ['complete' => false, 'rows' => null])
            ->where('chartData.table.0.sentiment', null)
            ->where('chartData.table.1.sentiment', null)));
});

test('the competitor filter keeps the figures of the whole comparison', function () {
    $world = competitorsWorld();

    $this->actingAs($world->tenant->owner)->get(route('brands.competitors.index', [$world->brand, 'days' => 7, 'competitor' => $world->c2->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.competitor', $world->c2->id)
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->has('chartData.table', 3)
                ->where('chartData.table.2.rank', 3)));
});

test('without answers there is no rank and no change', function () {
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    $this->actingAs($world->tenant->owner)->get(route('brands.competitors.index', [$world->brand, 'days' => 7]))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.ranking', [])
            ->where('chartData.table.0.rank', null)
            ->where('chartData.table.0.change', null)
            ->where('chartData.table.0.visibility', ['value' => null, 'n' => 0, 'low_sample' => true])));
});
