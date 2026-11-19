<?php

namespace App\Http\Middleware;

use App\Scheduling\SchedulerIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only the Cloud Scheduler identity, with the scheduler audience, may call /internal/tick/* (ADR 0030). */
final class AuthenticateScheduler
{
    public function __construct(private SchedulerIdentity $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->tokens->accepts($request->bearerToken(), config('collection.scheduler.audience'), config('collection.scheduler.service_account')), 401);

        return $next($request);
    }
}
