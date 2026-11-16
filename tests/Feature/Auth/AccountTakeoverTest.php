<?php

use App\Mail\EmailAddressChangedMail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

// T39-F4: a hijacked session must not be enough to take over the account.

beforeEach(function () {
    Mail::fake();
    Notification::fake();
});

test('changing the e-mail address needs the password', function () {
    $user = User::factory()->create(['email' => 'owner@example.test']);

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => $user->name, 'email' => 'attacker@example.test'])
        ->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');

    expect($user->fresh()->email)->toBe('owner@example.test');
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('a wrong password does not change the e-mail address', function () {
    $user = User::factory()->create(['email' => 'owner@example.test']);

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'wrong'])
        ->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');

    expect($user->fresh()->email)->toBe('owner@example.test');
});

test('with the password the address changes, must be verified, and the old address is told', function () {
    $user = User::factory()->create(['email' => 'owner@example.test', 'name' => 'Owner']);

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => 'Owner', 'email' => 'new@example.test', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('new@example.test')
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
    Mail::assertSent(EmailAddressChangedMail::class, fn (EmailAddressChangedMail $mail) => $mail->hasTo('owner@example.test')
        && ! $mail->hasTo('new@example.test')
        && $mail->newEmail === 'new@example.test');
});

test('the change notice renders in the language of the request', function () {
    app()->setLocale('nb');

    $html = (new EmailAddressChangedMail('Owner', 'new@example.test'))->render();

    expect($html)->toContain(__('auth.email_changed.not_you'))
        ->and((new EmailAddressChangedMail('Owner', 'new@example.test'))->envelope()->subject)->toBe(__('auth.email_changed.subject'));
});

test('changing only the name needs no password', function () {
    $user = User::factory()->create(['email' => 'owner@example.test']);

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => 'New Name', 'email' => 'OWNER@example.test'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('New Name');
    Mail::assertNothingSent();
});

test('a session from before a password change is logged out on the Fortify routes too', function () {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    // The session has only used the app routes (auth:sanctum), so only that key is set.
    $oldHash = $user->getAuthPassword();
    $user->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

    $this->actingAs($user)
        ->withSession(['password_hash_sanctum' => $oldHash])
        ->put('/user/profile-information', ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'changed-elsewhere'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->fresh()->email)->toBe('owner@example.test');
});

test('a session from before a password change is logged out on the app routes too', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $oldHash = $user->getAuthPassword();
    $user->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $oldHash])
        ->get('/dashboard')
        ->assertRedirect(route('login'));

    // The session guard is logged out; the test's actingAs user stays cached on the sanctum guard only.
    $this->assertGuest('web');
});

test('a current session keeps working and gets both password keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => 'Still Here', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(session('password_hash_web'))->toBeString()
        ->and(session('password_hash_sanctum'))->toBe(session('password_hash_web'));
});

test('after changing the password the own session keeps working on both kinds of routes', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->put('/user/password', ['current_password' => 'password', 'password' => 'a-new-Password-123', 'password_confirmation' => 'a-new-Password-123'])
        ->assertSessionHasNoErrors();

    $this->put('/user/profile-information', ['name' => 'After', 'email' => $user->email])->assertSessionHasNoErrors();
    $this->get('/dashboard')->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('after logging out other sessions the own session keeps working on the Fortify routes', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->delete('/user/other-browser-sessions', ['password' => 'password'])->assertSessionHasNoErrors();

    $this->put('/user/profile-information', ['name' => 'After', 'email' => $user->email])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->name)->toBe('After');
});

test('password confirmation is rate limited', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors();
    }

    $this->actingAs($user)->post('/user/confirm-password', ['password' => 'password'])->assertStatus(429);
});

test('logging out other sessions is rate limited, so it is no way to guess the password', function () {
    $user = User::factory()->withPersonalTeam()->create();

    foreach (range(1, 5) as $_) {
        $this->actingAs($user)->delete('/user/other-browser-sessions', ['password' => 'wrong'])->assertSessionHasErrors();
    }

    $this->actingAs($user)->delete('/user/other-browser-sessions', ['password' => 'password'])->assertStatus(429);
});

test('deleting the account is rate limited, so it is no way to guess the password', function () {
    $user = User::factory()->withPersonalTeam()->create();

    foreach (range(1, 5) as $_) {
        $this->actingAs($user)->delete('/user', ['password' => 'wrong'])->assertSessionHasErrors();
    }

    $this->actingAs($user)->delete('/user', ['password' => 'password'])->assertStatus(429);
    expect($user->fresh())->not->toBeNull();
});

test('an e-mail field sent as a list gives a validation error, not a server error', function () {
    $this->post('/forgot-password', ['email' => ['a@example.test']])->assertSessionHasErrors('email');
});

test('the forgot-password answer is the same for a known and an unknown address', function () {
    $user = User::factory()->create(['email' => 'known@example.test']);

    $known = $this->post('/forgot-password', ['email' => 'known@example.test']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);
    // A second request inside the broker's throttle window gives the same answer too.
    $again = $this->post('/forgot-password', ['email' => 'known@example.test']);

    foreach ([$known, $unknown, $again] as $response) {
        $response->assertSessionHasNoErrors()->assertSessionHas('status', trans('passwords.sent'));
    }
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('forgot-password is rate limited per IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/forgot-password', ['email' => "nobody{$i}@example.test"])->assertRedirect();
    }

    $this->post('/forgot-password', ['email' => 'nobody6@example.test'])->assertStatus(429);
});
