<?php

use App\Models\User;

test('a guest can choose a language and the login page follows it', function () {
    $this->from('/login')->put(route('locale.update'), ['locale' => 'sv'])->assertRedirect('/login');

    $this->get('/login')->assertInertia(fn ($page) => $page
        ->where('locale', 'sv')
        ->has('locales', 3));
});

test('a signed-in user stores the language on the account', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect('/dashboard');

    expect($user->fresh()->locale)->toBe('en')
        ->and($other->fresh()->locale)->toBeNull();

    $this->actingAs($user->fresh())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

test('an unknown language is rejected', function () {
    $this->from('/login')->put(route('locale.update'), ['locale' => 'de'])->assertSessionHasErrors('locale');
});
