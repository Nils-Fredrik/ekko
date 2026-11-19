<?php

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;

test('the command palette returns only the current tenant', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->create(['user_id' => $owner->id, 'name' => 'Palette A']);
    $owner->switchTeam($tenant);

    $otherOwner = User::factory()->withPersonalTeam()->create();
    $other = Team::factory()->tenant()->create(['user_id' => $otherOwner->id, 'name' => 'Palette B']);

    app(TenantContext::class)->run($tenant->id, function (): void {
        $brand = Brand::factory()->create(['name' => 'Visible Atlas']);
        $market = Market::factory()->for($brand)->create();
        Prompt::factory()->for($market)->create(['text' => 'Visible prompt about Atlas?']);
        Competitor::factory()->for($brand)->create(['name' => 'Visible Rival']);
    });

    app(TenantContext::class)->run($other->id, function (): void {
        $brand = Brand::factory()->create(['name' => 'Hidden Atlas']);
        $market = Market::factory()->for($brand)->create();
        Prompt::factory()->for($market)->create(['text' => 'Hidden prompt about Atlas?']);
        Competitor::factory()->for($brand)->create(['name' => 'Hidden Rival']);
    });

    $this->actingAs($owner)->getJson(route('palette.search', ['q' => 'Atlas']))
        ->assertOk()
        ->assertJsonPath('brands.0.label', 'Visible Atlas')
        ->assertJsonCount(1, 'brands')
        ->assertJsonPath('prompts.0.label', 'Visible prompt about Atlas?')
        ->assertJsonCount(1, 'prompts')
        ->assertJsonMissing(['label' => 'Hidden Atlas']);

    $this->actingAs($owner)->getJson(route('palette.search', ['q' => 'Hidden']))
        ->assertOk()
        ->assertJsonCount(0, 'brands')
        ->assertJsonCount(0, 'prompts')
        ->assertJsonCount(0, 'competitors');

    $this->actingAs($owner)->getJson(route('palette.search', ['q' => 'Rival']))
        ->assertOk()
        ->assertJsonPath('competitors.0.label', 'Visible Rival')
        ->assertJsonCount(1, 'competitors');

    $this->actingAs($owner)->getJson(route('palette.search', ['q' => '%']))
        ->assertOk()
        ->assertJsonCount(0, 'brands')
        ->assertJsonCount(0, 'prompts')
        ->assertJsonCount(0, 'competitors');
});

test('a guest cannot search the command palette', function () {
    $this->get(route('palette.search', ['q' => 'Atlas']))->assertRedirect(route('login'));
});
