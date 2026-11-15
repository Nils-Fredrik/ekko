<?php

namespace App\Http\Middleware;

use App\Scheduling\SchedulerIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthenticateScheduler
{
    public function __construct(private SchedulerIdentity $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $audience = config('collection.scheduler.audience');
        $email = config('collection.scheduler.service_account');
        abort_unless(is_string($token) && $token !== '' && is_string($audience) && $audience !== '' && is_string($email) && $email !== '', 401);
        try {
            $claims = $this->tokens->verify($token);
        } catch (Throwable) {
            $claims = false;
        }
        abort_unless(is_array($claims) && ($claims['iss'] ?? null) === 'https://accounts.google.com'
            && ($claims['aud'] ?? null) === $audience && is_int($claims['exp'] ?? null) && $claims['exp'] > time()
            && ($claims['email'] ?? null) === $email && ($claims['email_verified'] ?? false) === true, 401);

        return $next($request);
    }
}
