<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Jetstream\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs a session out when the user's password changed elsewhere, on the app routes (auth:sanctum) and
 * on the Fortify routes (auth:web) alike (T39-F4).
 *
 * Laravel stores the password hash under a key per guard. The app routes run as sanctum and the Fortify
 * routes as web, so a session that has only used the app has no web key. The base middleware then stores
 * the current hash on the first Fortify request instead of comparing it: a session hijacked before a
 * password change or "log out other sessions" could still change the e-mail address. Every known key is
 * checked here, and both are written after a request that passed.
 */
class AuthenticateSessionAcrossGuards extends AuthenticateSession
{
    private const GUARDS = ['web', 'sanctum'];

    /**
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, Closure $next)
    {
        $password = $request->hasSession() ? $request->user()?->getAuthPassword() : null;

        if (is_string($password) && $password !== '') {
            foreach (self::GUARDS as $guard) {
                $stored = $request->session()->get('password_hash_'.$guard);

                if (is_string($stored) && ! $this->validatePasswordHash($password, $stored)) {
                    $this->logout($request);
                }
            }
        }

        return parent::handle($request, $next);
    }

    /**
     * @param  Request  $request
     */
    protected function storePasswordHashInSession($request)
    {
        parent::storePasswordHashInSession($request);

        $hash = $request->session()->get('password_hash_'.$this->auth->getDefaultDriver());

        foreach (self::GUARDS as $guard) {
            $request->session()->put('password_hash_'.$guard, $hash);
        }
    }
}
