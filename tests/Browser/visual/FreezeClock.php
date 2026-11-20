<?php

namespace Tests\Browser\Visual;

use Closure;
use Illuminate\Support\Facades\Date;

/** Test router only: freeze page data, then restore real time before session/CSRF cookies are written. */
final class FreezeClock
{
    public function handle(mixed $request, Closure $next): mixed
    {
        Date::setTestNow('2026-10-16 12:00:00 UTC');
        try {
            return $next($request);
        } finally {
            Date::setTestNow();
        }
    }
}
