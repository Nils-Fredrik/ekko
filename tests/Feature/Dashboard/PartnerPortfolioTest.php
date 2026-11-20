<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AggDaily;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * U10 acceptance: the partner overview shows 50 clients without a noticeable delay. The first response only
 * names the clients and never reads the aggregates; the figures come in one deferred request, at most two
 * aggregate reads per brand on a cold cache and none on a warm cache.
 */
test('the portfolio of 50 clients reads no aggregates in the first response and few in the deferred one', function () {
    $this->travelTo('2026-11-17 12:00:00');
    $partner = Team::factory()->tenant()->create(['name' => 'Byra']);
    $engine = Engine::factory()->create();
    $context = app(TenantContext::class);
    foreach (range(1, 50) as $i) {
        $client = Team::factory()->tenant()->create(['name' => sprintf('Kunde %02d', $i), 'partner_team_id' => $partner->id, 'timezone' => 'UTC']);
        $context->set($client->id);
        $brand = Brand::factory()->create();
        $market = Market::factory()->for($brand)->create();
        $prompt = Prompt::factory()->for($market)->create();
        foreach (['2026-10-10', '2026-11-10'] as $date) {
            AggDaily::factory()->create([
                'market_id' => $market->id,
                'brand_id' => $brand->id,
                'engine_key' => $engine->key,
                'prompt_id' => $prompt->id,
                'date' => $date,
                'responses' => 20,
                'brand_mentioned' => $i % 20,
            ]);
        }
    }
    $context->forget();

    $advisor = User::factory()->withTwoFactor()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $advisor->switchTeam($partner);

    $aggregateReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$aggregateReads): void {
        if (str_contains($query->sql, 'agg_daily')) {
            $aggregateReads++;
        }
    });

    $this->actingAs($advisor)->get(route('partner.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('clients', 50)->missing('portfolioGraphs'));
    expect($aggregateReads)->toBe(0);

    $deferred = fn () => $this->actingAs($advisor)->get(route('partner.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Partner/Index',
        'X-Inertia-Partial-Data' => 'portfolioGraphs',
    ])->assertOk()->assertJsonCount(50, 'props.portfolioGraphs');

    $deferred()->assertJsonPath('props.portfolioGraphs.0.visibility.n', 20)
        ->assertJsonPath('props.portfolioGraphs.0.previous_visibility.n', 20);
    expect($aggregateReads)->toBeLessThanOrEqual(2 * 50);

    $aggregateReads = 0;
    $deferred();
    expect($aggregateReads)->toBe(0);
});
