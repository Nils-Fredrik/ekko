<?php

namespace App\Actions\Social;

use App\Models\SocialAccount;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Finds the user a Google or Microsoft account may log in as (docs/spec.md 5.4, ADR 0006).
 *
 * There is no open registration, so an account only gets access when
 * 1. it is already linked to a user, or
 * 2. its verified e-mail address belongs to an existing user (the account is linked), or
 * 3. its verified e-mail address has an unexpired team invitation (the user is created and linked).
 * Anything else returns null and creates nothing.
 */
final class ResolveSocialUser
{
    public function resolve(string $provider, SocialiteUser $social): ?User
    {
        $providerUserId = (string) $social->getId();

        if ($providerUserId === '') {
            return null;
        }

        $linked = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linked !== null) {
            return $linked->user;
        }

        $email = Str::lower(trim((string) $social->getEmail()));

        if ($email === '' || ! $this->emailIsVerified($provider, $social)) {
            return null;
        }

        return DB::transaction(function () use ($provider, $providerUserId, $email, $social): ?User {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user === null) {
                $invited = TeamInvitation::query()->active()->whereRaw('lower(email) = ?', [$email])->exists();

                if (! $invited) {
                    return null;
                }

                $user = new User;
                $user->forceFill([
                    'name' => $social->getName() ?: $email,
                    'email' => $email,
                    // No password login until the user asks for a reset link.
                    'password' => Str::random(64),
                ]);
            }

            // The provider has verified the address, so the user has too (ADR 0006).
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->save();

            // One account per provider per user: a different Google account for the same user is refused.
            if ($user->socialAccounts()->where('provider', $provider)->exists()) {
                return null;
            }

            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
            ]);

            return $user;
        });
    }

    /**
     * Google says so in the profile. Microsoft returns the mailbox address (Graph "mail", or the
     * user principal name) of an account the directory has verified.
     */
    private function emailIsVerified(string $provider, SocialiteUser $social): bool
    {
        if ($provider === 'google') {
            $raw = $social instanceof \Laravel\Socialite\Two\User ? $social->user : [];

            return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }
}
