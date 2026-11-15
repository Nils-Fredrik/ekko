<?php

namespace Tests\Support\Onboarding;

use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\PageTransport;
use App\Onboarding\Fetch\TransportResponse;

/** A transport that serves canned responses by address and records what it was asked. */
final class FakePageTransport implements PageTransport
{
    /** @var list<array{url: string, host: string, ip: string, maxBytes: int, timeout: int}> */
    public array $requests = [];

    /** @param  array<string, TransportResponse|FetchFailed>  $routes  url => response; a missing url answers 404 */
    public function __construct(public array $routes = []) {}

    public function get(string $url, string $host, string $ip, int $maxBytes, int $timeoutSeconds): TransportResponse
    {
        $this->requests[] = ['url' => $url, 'host' => $host, 'ip' => $ip, 'maxBytes' => $maxBytes, 'timeout' => $timeoutSeconds];
        $route = $this->routes[$url] ?? new TransportResponse(404, 'text/html', null, 'not found');
        if ($route instanceof FetchFailed) {
            throw $route;
        }

        return $route;
    }

    public static function html(string $body, ?string $type = 'text/html; charset=utf-8'): TransportResponse
    {
        return new TransportResponse(200, $type, null, $body);
    }

    public static function redirect(string $location, int $status = 302): TransportResponse
    {
        return new TransportResponse($status, 'text/html', $location, '');
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_column($this->requests, 'url');
    }
}
