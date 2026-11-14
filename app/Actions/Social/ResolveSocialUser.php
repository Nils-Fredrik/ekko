<?php

namespace App\Actions\Social;

use App\Models\SocialAccount;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Finds the user a Google or Microsoft account may log in as (docs/spec.md 5.4, ADR 0006).
 *
 * There is no open registration, so an account only gets access when
 * 1. it is already linked to a user, or
 * 2. its e-mail address is verified by the provider AND belongs to an existing user whose own
 *    address is verified (the account is linked), or
 * 3. its e-mail address is verified by the provider and has an unexpired team invitation (the user
 *    is created, linked, and joins the invited teams).
 * Anything else returns null and changes nothing.
 *
 * Whether the provider verified the address is decided by the caller, per provider, in one place
 * (SocialLoginController): Google "email_verified", Microsoft the "xms_edov" claim.
 */
final class ResolveSocialUser
{
    public function resolve(string $provider, SocialiteUser $social, bool $emailVerified): ?User
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

        if ($email === '' || ! $emailVerified) {
            return null;
        }

        return DB::transaction(fn (): ?User => $this->linkOrCreate($provider, $providerUserId, $email, $social));
    }

    private function linkOrCreate(string $provider, string $providerUserId, string $email, SocialiteUser $social): ?User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null) {
            // An unverified address may have been typed in by someone else (finding #140), and one
            // account per provider per user. Refuse before anything is written.
            if (! $user->hasVerifiedEmail() || $user->socialAccounts()->where('provider', $provider)->exists()) {
                return null;
            }

            $user->socialAccounts()->create(['provider' => $provider, 'provider_user_id' => $providerUserId]);

            return $user;
        }

        $invitations = TeamInvitation::query()->active()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->get();

        if ($invitations->isEmpty()) {
            return null;
        }

        $user = new User;
        $user->forceFill([
            'name' => $social->getName() ?: $email,
            'email' => $email,
            // No password login until the user asks for a reset link.
            'password' => Str::random(64),
            'email_verified_at' => now(),
        ])->save();

        $user->socialAccounts()->create(['provider' => $provider, 'provider_user_id' => $providerUserId]);

        $this->acceptInvitations($user, $invitations);

        return $user;
    }

    /**
     * The login proved that the user owns the invited address, so the invitations are accepted
     * now, the way Jetstream does it, and the first team becomes the current team. Otherwise the
     * new user would sit in no tenant.
     *
     * @param  iterable<TeamInvitation>  $invitations
     */
    private function acceptInvitations(User $user, iterable $invitations): void
    {
        foreach ($invitations as $invitation) {
            try {
                app(AddsTeamMembers::class)->add($invitation->team->owner, $invitation->team, $invitation->email, $invitation->role);
            } catch (AuthorizationException|ValidationException) {
                // Not valid any more (for example the tenant became a client tenant): left for the admin.
                continue;
            }

            $invitation->delete();

            if ($user->current_team_id === null) {
                $user->switchTeam($invitation->team);
            }
        }
    }
}
