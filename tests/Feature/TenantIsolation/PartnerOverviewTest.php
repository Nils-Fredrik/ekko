<?php

use App\Models\Team;
use App\Models\User;

test('a partner advisor sees only the clients linked to the current partner', function () {
    $partner = Team::factory()->tenant()->create(['name' => 'Byra']);
    $client = Team::factory()->tenant()->create(['name' => 'Kunde', 'partner_team_id' => $partner->id]);
    $otherPartner = Team::factory()->tenant()->create(['name' => 'Annet byra']);
    $otherClient = Team::factory()->tenant()->create(['name' => 'Annen kunde', 'partner_team_id' => $otherPartner->id]);
    $unrelated = Team::factory()->tenant()->create(['name' => 'Fremmed']);

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
            ->where('clients.0.alerts', 0))
        ->assertDontSee($otherClient->name)
        ->assertDontSee($unrelated->name);

    $reader = User::factory()->create();
    $partner->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($partner);
    $this->actingAs($reader)->get(route('partner.index'))->assertForbidden();

    $advisor->switchTeam($client);
    $this->actingAs($advisor)->get(route('partner.index'))->assertForbidden();
});
