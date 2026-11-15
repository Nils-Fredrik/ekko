<?php

namespace App\BotLogs;

use App\Models\Brand;

final class BrandLogDomains
{
    /** @return array<string, bool> Domain => include subdomains. */
    public static function for(Brand $brand): array
    {
        $domains = [];
        $primary = self::normalize($brand->primary_domain ?? '');
        if ($primary !== null) {
            $domains[$primary] = false;
        }

        foreach ($brand->loadMissing('domains')->domains as $registration) {
            $domain = self::normalize($registration->domain);
            if ($domain !== null) {
                $domains[$domain] = $registration->include_subdomains || ($domains[$domain] ?? false);
            }
        }

        return $domains;
    }

    public static function normalize(string $domain): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && str_contains($domain, '.')
            ? $domain
            : null;
    }
}
