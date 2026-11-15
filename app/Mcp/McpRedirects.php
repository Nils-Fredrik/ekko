<?php

namespace App\Mcp;

/**
 * The callback addresses Claude uses, and the only ones Ekko accepts for the Claude connection (as in Varde):
 * claude.ai and claude.com (web, Desktop, mobile) and Claude Code, which uses a loopback address with a port
 * that changes each time. The port is therefore ignored for loopback addresses (RFC 8252 7.3).
 */
final class McpRedirects
{
    public const HOSTED = ['https://claude.ai/api/mcp/auth_callback', 'https://claude.com/api/mcp/auth_callback'];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private const LOOPBACK_PATH = '/callback';

    public static function allowed(string $uri): bool
    {
        return in_array($uri, self::HOSTED, true) || self::isLoopback($uri);
    }

    /** Claude Code: http, localhost, 127.0.0.1 or [::1], any port, path /callback, no user info or fragment. */
    public static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'http' || isset($parts['user']) || isset($parts['fragment']) || isset($parts['query'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK_HOSTS, true) && ($parts['path'] ?? '') === self::LOOPBACK_PATH;
    }

    /**
     * Whether the address in the request is one the client registered: exact, except the port of loopback addresses.
     *
     * @param  array<int, string>  $registered
     */
    public static function matches(string $uri, array $registered): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        if (! self::isLoopback($uri)) {
            return false;
        }
        $strip = fn (string $u): string => (string) preg_replace('#^(http://\[::1\]|http://[^/:]+)(:\d+)?#i', '$1', strtolower($u));

        foreach ($registered as $candidate) {
            if (self::isLoopback($candidate) && $strip($candidate) === $strip($uri)) {
                return true;
            }
        }

        return false;
    }

    /** The host shown on the consent page and in the connection list. */
    public static function host(string $uri): string
    {
        $host = parse_url($uri, PHP_URL_HOST);

        return is_string($host) ? $host : $uri;
    }
}
