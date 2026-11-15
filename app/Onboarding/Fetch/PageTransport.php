<?php

namespace App\Onboarding\Fetch;

/**
 * One HTTPS GET to an address that has already been checked. The transport must connect to $ip (not look
 * the host up again), use $host for TLS and the Host header, never follow redirects, and read at most
 * $maxBytes + 1 bytes of the decoded body.
 */
interface PageTransport
{
    public function get(string $url, string $host, string $ip, int $maxBytes, int $timeoutSeconds): TransportResponse;
}
