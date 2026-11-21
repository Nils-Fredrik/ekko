<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

// U18: resources/js/format.js formats dates in the active tenant's time zone (docs/ux-arbeidsflate.md 4.2).

test('a signed-in user gets the time zone of the current tenant', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam->forceFill(['timezone' => 'Europe/Stockholm'])->save();

    $this->actingAs($user)->get('/dev/components')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.timezone', 'Europe/Stockholm'));
});

test('a guest has no tenant time zone', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant', null));
});
