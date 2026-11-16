<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // An unknown or throttled address gets the same answer as a known one, so the form does not tell
        // who has an account (T39-F4).
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn () => new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // The single sign-on redirect and callback: per IP, so a script cannot probe accounts.
        RateLimiter::for('sso', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Routes that check the current password: per user and IP, so a hijacked session cannot guess the
        // password that unlocks acting as a tenant, two-factor changes or a new e-mail address (T39-F4).
        RateLimiter::for('password-check', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->getAuthIdentifier().'|'.$request->ip()));

        // Reset links: per IP and per address. The answer is the same whether the address exists or not.
        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perMinute(5)->by('ip|'.$request->ip()),
            Limit::perHour(5)->by('email|'.Str::lower((string) $request->input('email'))),
        ]);

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
