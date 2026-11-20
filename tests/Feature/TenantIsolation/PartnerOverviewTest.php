<?php

use App\Enums\TenantStatus;
use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;

test('a partner advisor sees only the clients linked to the current partner', function () {
    $this->travelTo('2026-11-17 12:00:00');
    $partner = Team::factory()->tenant()->create(['name' => 'Byra', 'timezone' => 'Pacific/Kiritimati']);
    $client = Team::factory()->tenant()->create(['name' => 'Kunde', 'partner_team_id' => $partner->id, 'timezone' => 'UTC']);
    $otherPartner = Team::factory()->tenant()->create(['name' => 'Annet byra']);
    $otherClient = Team::factory()->tenant()->create(['name' => 'Annen kunde', 'partner_team_id' => $otherPartner->id, 'timezone' => 'UTC']);
    $unrelated = Team::factory()->tenant()->create(['name' => 'Fremmed']);
    $engine = Engine::factory()->create();
    $context = app(TenantContext::class);

    $context->set($client->id);
    foreach ([[10, 10], [30, 0]] as [$responses, $mentioned]) {
        $brand = Brand::factory()->create();
        $market = Market::factory()->for($brand)->create();
        $prompt = Prompt::factory()->for($market)->create();
        AggDaily::factory()->create([
            'market_id' => $market->id,
            'brand_id' => $brand->id,
            'engine_key' => $engine->key,
            'prompt_id' => $prompt->id,
            'date' => '2026-10-21',
            'responses' => $responses,
            'brand_mentioned' => $mentioned,
        ]);
    }
    Alert::factory()->create(['triggered_at' => '2026-10-21 12:00:00']);

    $context->set($otherClient->id);
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
        'brand_mentioned' => 10,
    ]);
    Alert::factory()->create(['triggered_at' => '2026-10-21 12:00:00']);
    $context->forget();

    $advisor = User::factory()->withTwoFactor()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $advisor->switchTeam($partner);

    $this->actingAs($advisor)->get(route('partner.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Partner/Index')
            ->has('clients', 1)
            ->where('clients.0.id', $client->id)
            ->where('clients.0.name', 'Kunde')
            // The first response only names the clients; the figures are deferred (U10).
            ->missing('clients.0.visibility')
            ->missing('portfolioGraphs')
            ->loadDeferredProps(fn ($page) => $page
                ->has('portfolioGraphs', 1)
                ->where('portfolioGraphs.0.id', $client->id)
                ->where('portfolioGraphs.0.visibility.value', 0.25)
                ->where('portfolioGraphs.0.visibility.n', 40)
                ->where('portfolioGraphs.0.open_alerts', 1)))
        ->assertDontSee($otherClient->name)
        ->assertDontSee($unrelated->name);

    $reader = User::factory()->create();
    $partner->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($partner);
    $this->actingAs($reader)->get(route('partner.index'))->assertForbidden();

    $advisor->switchTeam($client);
    $this->actingAs($advisor)->get(route('partner.index'))->assertForbidden();

    $closed = Team::factory()->tenant()->status(TenantStatus::Closed)->create();
    $closedAdvisor = User::factory()->withTwoFactor()->create();
    $closed->users()->attach($closedAdvisor, ['role' => 'partner_advisor']);
    $closedAdvisor->forceFill(['current_team_id' => $closed->id])->save();
    $this->actingAs($closedAdvisor)->get(route('profile.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewPartner', false));
});
