<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AggDaily;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'Europe/Oslo'));
    config(['ekko.insights.enabled' => false]);
    Cache::flush();
    $this->world = MetricsWorld::create();
    $this->daily = AggDaily::factory()->create([
        'market_id' => $this->world->market->id, 'engine_key' => $this->world->engine->key,
        'prompt_id' => $this->world->p1->id, 'date' => '2026-11-19',
        'responses' => 20, 'brand_mentioned' => 10,
    ]);
    $owner = $this->world->tenant->owner;
    $owner->switchTeam($this->world->tenant);
    $this->actingAs($owner);
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()));
    $this->url = '/brands/'.$this->world->brand->id;
    $this->queries = [];
    DB::listen(function (QueryExecuted $query) {
        $this->queries[] = strtolower($query->sql);
    });
});

test('non-metric partial reloads skip dashboard, action and model-change queries', function (string $prop) {
    $response = $this->get($this->url, [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Brands/Show',
        'X-Inertia-Partial-Data' => $prop,
    ])->assertOk()->assertJsonMissingPath('props.summary')->assertJsonMissingPath('props.chartData');

    if ($prop === 'insight') {
        $response->assertJsonPath('props.insight', null)->assertJsonMissingPath('props.analysisFilters');
        expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, 'saved_views')))->toBeEmpty();
    } else {
        $response->assertJsonPath('props.analysisFilters.views', []);
    }
    foreach (['agg_daily', '"actions"', 'model_changes'] as $table) {
        expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, $table)))->toBeEmpty();
    }
})->with(['insight', 'analysisFilters']);

test('chart reloads use only chart queries and a warm graph cache needs no aggregate reads', function () {
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Brands/Show', 'X-Inertia-Partial-Data' => 'chartData'];
    $this->get($this->url, $headers)->assertOk()
        ->assertJsonPath('props.chartData.series.0.metrics.visibility.value', 0.5)
        ->assertJsonMissingPath('props.summary')->assertJsonMissingPath('props.insight');
    expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, 'agg_daily')))->not->toBeEmpty();
    expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, 'grouping sets')))->toBeEmpty();

    $this->queries = [];
    $this->get($this->url, $headers)->assertOk()->assertJsonPath('props.chartData.series.0.metrics.visibility.value', 0.5);
    expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, 'agg_daily')))->toBeEmpty();
});

test('dashboard props share one aggregate scan but read fresh figures on the next request', function (bool $partial) {
    $headers = $partial ? [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Brands/Show',
        'X-Inertia-Partial-Data' => 'summary,series,voice,engines',
    ] : [];

    foreach ([10 => 0.5, 16 => 0.8] as $mentions => $visibility) {
        app(TenantContext::class)->run($this->world->tenant->id, fn () => $this->daily->update(['brand_mentioned' => $mentions]));
        $this->queries = [];
        $response = $this->get($this->url, $headers)->assertOk();
        if ($partial) {
            $response->assertJsonPath('props.summary.visibility.value', $visibility)
                ->assertJsonPath('props.series.0.visibility.value', $visibility)
                ->assertJsonPath('props.engines.0.visibility.value', $visibility)
                ->assertJsonStructure(['props' => ['voice']]);
        } else {
            $response->assertInertia(fn ($page) => $page
                ->where('summary.visibility.value', $visibility)
                ->where('summary.visibility.n', 20)
                ->where('series.0.visibility.value', $visibility)
                ->where('engines.0.visibility.value', $visibility)
                ->has('voice')->missing('insight')->missing('chartData'));
        }
        expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, 'grouping sets')))->toHaveCount(1);
    }
})->with(['full page' => false, 'partial metrics' => true]);

test('partial props cannot bypass tenant authorization even with a warm cache', function (string $prop) {
    $this->get($this->url, [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Brands/Show', 'X-Inertia-Partial-Data' => 'chartData',
    ])->assertOk();
    $other = MetricsWorld::create();
    $otherOwner = $other->tenant->owner;
    $otherOwner->switchTeam($other->tenant);
    $this->queries = [];

    $this->actingAs($otherOwner)->get($this->url, [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Brands/Show', 'X-Inertia-Partial-Data' => $prop,
    ])->assertNotFound();
    expect(collect($this->queries)->filter(fn ($sql) => str_contains($sql, 'agg_daily')))->toBeEmpty();
})->with(['insight', 'chartData', 'summary']);
