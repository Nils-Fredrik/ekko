<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Social\ResolveSocialUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Login with Google (docs/spec.md 5.4, ADR 0008). Only for users who exist or have an
 * unexpired invitation; an uninvited account gets no access and no user.
 */
class SocialLoginController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        $this->ensureConfigured($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider, ResolveSocialUser $resolver): RedirectResponse
    {
        $this->ensureConfigured($provider);

        try {
            $social = Socialite::driver($provider)->user();
            $user = $resolver->resolve($provider, $social, $this->verifiedEmail($provider, $social));
        } catch (Throwable $e) {
            // Includes a wrong state, a cancelled consent screen and provider errors. No details to the user.
            Log::warning('sso.failed', ['provider' => $provider, 'exception' => $e::class]);

            return $this->deny(__('auth.sso.failed'));
        }

        if (! $user instanceof User) {
            Log::info('sso.denied', ['provider' => $provider]);

            return $this->deny(__('auth.sso.not_invited'));
        }

        // A user with two-factor authentication goes through the same challenge as a password login.
        if ($user->hasConfirmedTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::guard(config('fortify.guard'))->login($user);
        $request->session()->regenerate();

        // An invitation link opened while signed out is the intended URL, but SSO has just accepted
        // (and deleted) that invitation, so going back there would be a 404.
        $intended = (string) $request->session()->get('url.intended', '');
        if ($user->wasRecentlyCreated && str_contains($intended, '/team-invitations/')) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * The e-mail address the provider has verified, or null. Decided per provider and nowhere else.
     * Google: the profile address when "email_verified" is true. Any other provider gives null until
     * it has its own rule here (ADR 0008 says what Microsoft would need).
     */
    private function verifiedEmail(string $provider, SocialiteUser $social): ?string
    {
        if ($provider === 'google') {
            $raw = $social instanceof SocialiteTwoUser ? $social->user : [];

            return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $social->getEmail() : null;
        }

        return null;
    }

    private function deny(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['sso' => $message]);
    }

    /**
     * A provider without credentials does not exist, so there is nothing to start.
     */
    private function ensureConfigured(string $provider): void
    {
        abort_unless(
            in_array($provider, config('ekko.sso_providers'), true)
                && filled(config("services.{$provider}.client_id"))
                && filled(config("services.{$provider}.client_secret")),
            404,
        );
    }
}
