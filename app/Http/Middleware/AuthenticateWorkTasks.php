<?php

namespace App\Http\Middleware;

use App\Scheduling\SchedulerIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the Cloud Tasks wake-up identity, with its own audience, may call /internal/work/* (T40-F4, ADR 0033). The
 * Scheduler's token, a user session or an unconfigured environment is refused.
 */
final class AuthenticateWorkTasks
{
    public function __construct(private SchedulerIdentity $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->tokens->accepts($request->bearerToken(), config('ekko.work.cloud_tasks.audience'), config('ekko.work.cloud_tasks.service_account')), 401);

        return $next($request);
    }
}
