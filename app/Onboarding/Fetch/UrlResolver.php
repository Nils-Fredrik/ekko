<?php

namespace App\Onboarding\Fetch;

/** Resolves a link or a redirect target against the address of the page it came from (RFC 3986 5.2, simplified). */
final class UrlResolver
{
    /** @return string|null an absolute address without fragment, or null when the reference is unusable */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || preg_match('/[\x00-\x1f\x7f]/', $reference) === 1) {
            return null;
        }
        $reference = preg_replace('/#.*$/', '', $reference) ?? '';
        if ($reference === '') {
            return null;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'];
        $authority = $baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $reference) === 1) {
            // Absolute. A reference like "mailto:x" or "javascript:x" is kept and refused later by the scheme check.
            return $reference;
        }
        if (str_starts_with($reference, '//')) {
            return "{$scheme}:{$reference}";
        }

        $basePath = $baseParts['path'] ?? '/';
        if (str_starts_with($reference, '/')) {
            $path = $reference;
        } elseif (str_starts_with($reference, '?')) {
            return "{$scheme}://{$authority}{$basePath}{$reference}";
        } else {
            $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1).$reference;
        }

        $query = '';
        $queryAt = strpos($path, '?');
        if ($queryAt !== false) {
            $query = substr($path, $queryAt);
            $path = substr($path, 0, $queryAt);
        }

        return "{$scheme}://{$authority}".self::removeDotSegments($path).$query;
    }

    private static function removeDotSegments(string $path): string
    {
        $output = [];
        $segments = explode('/', $path);
        $last = end($segments);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }
        $result = implode('/', $output);
        if ($last === '.' || $last === '..') {
            $result .= '/';
        }

        return str_starts_with($result, '/') ? $result : '/'.$result;
    }
}
