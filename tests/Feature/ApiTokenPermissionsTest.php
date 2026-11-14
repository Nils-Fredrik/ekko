<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

// API tokens have read and write only (docs/spec.md 15.1).

test('api token permissions can be updated', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $token = $user->tokens()->create([
        'name' => 'Test Token',
        'token' => Str::random(40),
        'abilities' => ['read'],
    ]);

    $this->put('/user/api-tokens/'.$token->id, [
        'name' => $token->name,
        'permissions' => [
            'write',
            'missing-permission',
        ],
    ]);

    expect($user->fresh()->tokens->first())
        ->can('write')->toBeTrue()
        ->can('read')->toBeFalse()
        ->can('missing-permission')->toBeFalse();
});

test('role permissions are not offered as api token permissions', function () {
    expect(Jetstream::$permissions)->toBe(['read', 'write'])
        ->and(Jetstream::$defaultPermissions)->toBe(['read']);
});
