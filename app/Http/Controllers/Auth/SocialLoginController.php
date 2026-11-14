<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Social\ResolveSocialUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Login with Google or Microsoft (docs/spec.md 5.4, ADR 0006). Only for users who exist or have an
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
            $user = $resolver->resolve($provider, $social);
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

        return redirect()->intended(config('fortify.home'));
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
