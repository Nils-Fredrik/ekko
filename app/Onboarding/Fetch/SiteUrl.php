<?php

namespace App\Onboarding\Fetch;

/**
 * Validates and normalises the addresses the wizard may fetch (docs/spec.md 16): https only, the default
 * port only, no credentials, and a real public-looking host name. IP literals are refused outright, in every
 * notation, so the DNS check in {@see SafeHttpFetcher} is the only way to an address. This class does no
 * network access.
 */
final class SiteUrl
{
    /** Host names and suffixes that never name a public site. */
    private const LOCAL_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private'];

    /**
     * What the user typed in the domain field: a bare domain or a pasted address. Returns the host name only,
     * since the wizard always fetches over https, or null when it is not a usable public host name.
     */
    public static function hostFromUserInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $input) === 1) {
            return null;
        }
        if (! str_contains($input, '://')) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            return null;
        }

        return self::normaliseHost($parts['host'] ?? '');
    }

    /**
     * A strict check of an address that will really be requested.
     *
     * @return array{url: string, host: string} the address without fragment, and its normalised host
     *
     * @throws FetchFailed
     */
    public static function validate(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw FetchFailed::because(FetchFailed::INVALID_URL);
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw FetchFailed::because(FetchFailed::INVALID_URL);
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw FetchFailed::because(FetchFailed::INVALID_URL);
        }

        $host = self::normaliseHost($parts['host'] ?? '');
        if ($host === null) {
            throw FetchFailed::because(FetchFailed::INVALID_URL);
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return ['url' => "https://{$host}{$path}{$query}", 'host' => $host];
    }

    public static function normaliseHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || str_contains($host, '[') || str_contains($host, ':') || PublicAddress::isIpLiteral($host)) {
            return null;
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (! is_string($ascii) || $ascii === '') {
            return null;
        }

        $labels = explode('.', $ascii);
        if (count($labels) < 2 || strlen($ascii) > 253) {
            return null;
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return null;
            }
        }
        // 2130706433, 0x7f.1 and 127.1 are numbers the OS resolver reads as addresses. No real top-level domain is numeric.
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', end($labels)) === 1) {
            return null;
        }
        if (in_array(end($labels), self::LOCAL_SUFFIXES, true)) {
            return null;
        }

        return $ascii;
    }
}
