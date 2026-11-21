<?php

use App\Dashboard\DashboardPeriod;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AggDaily;
use App\Models\Prompt;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

test('history thresholds pick 7, 28 and 90 days', function () {
    expect(DashboardPeriod::lengthForHistory(0))->toBe(7)
        ->and(DashboardPeriod::lengthForHistory(6))->toBe(7)
        ->and(DashboardPeriod::lengthForHistory(22))->toBe(7)
        ->and(DashboardPeriod::lengthForHistory(23))->toBe(28)
        ->and(DashboardPeriod::lengthForHistory(71))->toBe(28)
        ->and(DashboardPeriod::lengthForHistory(72))->toBe(90);
});

test('the default analysis period follows distinct days with data', function () {
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    $prompts = Prompt::factory()->for($world->market)->count(6)->create();
    foreach (range(0, 3) as $day) {
        foreach ($prompts as $prompt) {
            AggDaily::factory()->create([
                'market_id' => $world->market->id,
                'prompt_id' => $prompt->id,
                'engine_key' => $world->engine->key,
                'date' => '2026-10-0'.($day + 1),
            ]);
        }
    }

    $this->actingAs($world->tenant->owner)->get(route('brands.show', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.days', 7)
            ->where('analysisFilters.automaticPeriod', true)
            ->where('period.measured_from', '2026-10-01'));

    $this->get(route('brands.show', [$world->brand, 'days' => 90]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.days', 90)
            ->where('analysisFilters.automaticPeriod', false));
});

test('twenty three days of data selects a 28 day period', function () {
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    foreach (range(0, 22) as $offset) {
        AggDaily::factory()->create([
            'market_id' => $world->market->id,
            'prompt_id' => $world->p1->id,
            'engine_key' => $world->engine->key,
            'date' => now()->subDays($offset)->toDateString(),
        ]);
    }

    $this->actingAs($world->tenant->owner)->get(route('brands.show', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('period.days', 28)->where('analysisFilters.automaticPeriod', true));
});

test('days older than the window do not stretch the period', function () {
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    foreach (range(40, 62) as $offset) {
        AggDaily::factory()->create([
            'market_id' => $world->market->id,
            'prompt_id' => $world->p1->id,
            'engine_key' => $world->engine->key,
            'date' => now()->subDays($offset)->toDateString(),
        ]);
    }

    $this->actingAs($world->tenant->owner)->get(route('brands.show', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('period.days', 7));
});

test('a deferred insight uses the automatic period', function () {
    Cache::flush();
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    AggDaily::factory()->create([
        'market_id' => $world->market->id,
        'prompt_id' => $world->p1->id,
        'engine_key' => $world->engine->key,
        'date' => now()->toDateString(),
    ]);
    config(['ekko.insights.enabled' => false]);

    $this->actingAs($world->tenant->owner)
        ->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()))
        ->get(route('brands.show', $world->brand), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Brands/Show',
            'X-Inertia-Partial-Data' => 'insight,analysisFilters',
        ])
        ->assertOk()
        ->assertJsonPath('props.insight', null)
        ->assertJsonPath('props.analysisFilters.query.days', 7)
        ->assertJsonPath('props.analysisFilters.automaticPeriod', true);
});
