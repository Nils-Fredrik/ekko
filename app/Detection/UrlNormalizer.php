<?php

namespace App\Detection;

/**
 * Normalizes citation URLs (docs/spec.md 8.1): tracking parameters (utm_*, gclid, fbclid, srsltid), a
 * trailing slash and a leading www. are removed. In addition the host is lowercased, a default port and
 * the fragment are dropped, and only http and https URLs are accepted.
 */
final class UrlNormalizer
{
    /**
     * @return array{url: string, domain: string}|null null when this is not an http(s) URL with a host
     */
    public function normalize(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) !== 1) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = $this->host($parts['host']);
        if ($host === null) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $query = $this->query($parts['query'] ?? '');

        return [
            'url' => $scheme.'://'.$host.($port !== null ? ':'.$port : '').$path.($query !== '' ? '?'.$query : ''),
            'domain' => $host,
        ];
    }

    /**
     * The comparable form of a host: lowercase, no trailing dot, internationalized names as punycode and
     * no leading www. Used for citation hosts and for registered brand and competitor domains alike.
     */
    public static function hostKey(string $host): string
    {
        $host = mb_strtolower(rtrim($host, '.'));
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = $ascii === false ? $host : $ascii;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function host(string $host): ?string
    {
        $host = self::hostKey($host);

        return $host === '' || ! str_contains($host, '.') && $host !== 'localhost' ? null : $host;
    }

    private function query(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, ['gclid', 'fbclid', 'srsltid'], true)) {
                continue;
            }
            $kept[] = $pair;
        }

        return implode('&', $kept);
    }
}
