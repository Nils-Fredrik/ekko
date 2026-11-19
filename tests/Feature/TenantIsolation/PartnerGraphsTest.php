<?php

use App\Enums\ActionStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Action;
use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;

test('deferred portfolio uses weighted tenant-local series and enforces partner access on warm cache', function () {
    $this->travelTo('2026-11-17 12:00:00');
    $partner = Team::factory()->tenant()->create(['name' => 'Byra']);
    $client = Team::factory()->tenant()->create(['name' => 'A kunde', 'partner_team_id' => $partner->id, 'timezone' => 'UTC']);
    $client2 = Team::factory()->tenant()->create(['name' => 'B kunde', 'partner_team_id' => $partner->id, 'timezone' => 'UTC']);
    $otherPartner = Team::factory()->tenant()->create(['name' => 'Annet byra']);
    $otherClient = Team::factory()->tenant()->create(['name' => 'Hemmelig kunde', 'partner_team_id' => $otherPartner->id, 'timezone' => 'UTC']);
    $engine = Engine::factory()->create();
    $context = app(TenantContext::class);

    $context->set($client->id);
    $firstAggregate = null;
    foreach ([[10, 10], [30, 0]] as [$responses, $mentioned]) {
        $brand = Brand::factory()->create();
        $market = Market::factory()->for($brand)->create();
        $prompt = Prompt::factory()->for($market)->create();
        foreach ([['2026-10-21', $responses, $mentioned], ['2026-10-20', 10, 5]] as [$date, $total, $mentions]) {
            $aggregate = AggDaily::factory()->create([
                'market_id' => $market->id,
                'brand_id' => $brand->id,
                'engine_key' => $engine->key,
                'prompt_id' => $prompt->id,
                'date' => $date,
                'responses' => $total,
                'brand_mentioned' => $mentions,
            ]);
            if ($firstAggregate === null && $date === '2026-10-21') {
                $firstAggregate = $aggregate->id;
            }
        }
    }
    Alert::factory()->create();
    Alert::factory()->create(['acknowledged_at' => now()]);
    Action::factory()->create(['brand_id' => $brand->id, 'status' => ActionStatus::New]);
    Action::factory()->create(['brand_id' => $brand->id, 'status' => ActionStatus::Done]);

    foreach ([$client2, $otherClient] as $other) {
        $context->set($other->id);
        $otherBrand = Brand::factory()->create();
        $otherMarket = Market::factory()->for($otherBrand)->create();
        $otherPrompt = Prompt::factory()->for($otherMarket)->create();
        AggDaily::factory()->create([
            'market_id' => $otherMarket->id,
            'brand_id' => $otherBrand->id,
            'engine_key' => $engine->key,
            'prompt_id' => $otherPrompt->id,
            'date' => '2026-10-21',
            'responses' => 10,
            'brand_mentioned' => 9,
        ]);
    }
    $context->forget();

    $advisor = User::factory()->withTwoFactor()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $advisor->switchTeam($partner);

    $check = function ($page, int $openAlerts = 1) use ($client, $client2): void {
        $page->has('portfolioGraphs', 2)
            ->where('portfolioGraphs.0.id', $client->id)
            ->has('portfolioGraphs.0.series', 28)
            ->where('portfolioGraphs.0.series.0.x', '2026-10-21')
            ->where('portfolioGraphs.0.series.0.value', 0.25)
            ->where('portfolioGraphs.0.series.0.n', 40)
            ->where('portfolioGraphs.0.series.1.value', null)
            ->where('portfolioGraphs.0.previous_visibility.value', 0.5)
            ->where('portfolioGraphs.0.change', -0.25)
            ->where('portfolioGraphs.0.open_alerts', $openAlerts)
            ->where('portfolioGraphs.0.open_actions', 1)
            ->where('portfolioGraphs.1.id', $client2->id)
            ->where('portfolioGraphs.1.series.0.value', 0.9);
    };
    $this->actingAs($advisor)->get(route('partner.index'))->assertOk()
        ->assertDontSee('Hemmelig kunde')
        ->assertInertia(fn ($page) => $page->missing('portfolioGraphs')->loadDeferredProps($check));

    $context->run($client->id, function () use ($firstAggregate): void {
        AggDaily::query()->whereKey($firstAggregate)->update(['brand_mentioned' => 0]);
        Alert::query()->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
    });

    $this->actingAs($advisor)->get(route('partner.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Partner/Index',
        'X-Inertia-Partial-Data' => 'portfolioGraphs',
    ])->assertOk()->assertDontSee('Hemmelig kunde')
        ->assertJsonPath('props.portfolioGraphs.0.id', $client->id)
        ->assertJsonPath('props.portfolioGraphs.0.series.0.value', 0.25)
        ->assertJsonPath('props.portfolioGraphs.0.open_alerts', 0)
        ->assertJsonPath('props.portfolioGraphs.1.id', $client2->id)
        ->assertJsonPath('props.portfolioGraphs.1.series.0.value', 0.9);

    $otherAdvisor = User::factory()->withTwoFactor()->create();
    $otherPartner->users()->attach($otherAdvisor, ['role' => 'partner_advisor']);
    $otherAdvisor->switchTeam($otherPartner);
    $this->actingAs($otherAdvisor)->get(route('partner.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Partner/Index',
        'X-Inertia-Partial-Data' => 'portfolioGraphs',
    ])->assertOk()->assertJsonCount(1, 'props.portfolioGraphs')
        ->assertJsonPath('props.portfolioGraphs.0.id', $otherClient->id)
        ->assertJsonPath('props.portfolioGraphs.0.series.0.value', 0.9);

    $reader = User::factory()->withTwoFactor()->create();
    $partner->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($partner);
    $this->actingAs($reader)->get(route('partner.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Partner/Index',
        'X-Inertia-Partial-Data' => 'portfolioGraphs',
    ])->assertForbidden();
});
