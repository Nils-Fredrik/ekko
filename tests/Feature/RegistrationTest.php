<?php

use Laravel\Fortify\Features;

// Ekko has no open registration. Users are invited by a tenant admin (docs/spec.md 5.4).

test('registration feature is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('registration screen cannot be rendered', function () {
    $this->get('/register')->assertNotFound();
});

test('new users cannot register', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});
