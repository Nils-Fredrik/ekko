<?php

namespace App\Http\Middleware;

use App\Logging\LogContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SetLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(LogContext::class);
        $context->clear();

        try {
            return $next($request);
        } catch (Throwable $failure) {
            $context->capture($failure);
            throw $failure;
        } finally {
            $context->clear();
        }
    }
}
