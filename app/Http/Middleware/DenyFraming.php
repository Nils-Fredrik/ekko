<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No page from Ekko may be shown in a frame on another site (T39-F5). Above all the consent page
 * /oauth/authorize, where a framed page could trick the user into approving a connection (clickjacking,
 * RFC 9700 4.16). Both headers: X-Frame-Options for older browsers, frame-ancestors for the rest.
 */
class DenyFraming
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        }

        return $response;
    }
}
