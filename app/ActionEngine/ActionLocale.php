<?php

namespace App\ActionEngine;

use App\Models\Market;
use App\Models\Team;

/**
 * The language of an action (docs/spec.md 11.3: the brand's language). A market action uses the market's
 * language; a brand-level action (crawler_access) uses the tenant's default locale. Templates exist in nb, sv
 * and en, so {@see appLocale()} maps the language tag to one of them.
 */
final class ActionLocale
{
    public static function tag(?Market $market, ?Team $tenant): string
    {
        $tag = trim((string) ($market->language ?? ''));
        if ($tag === '') {
            $tag = trim((string) ($tenant->default_locale ?? ''));
        }

        return $tag === '' ? 'nb' : $tag;
    }

    public static function appLocale(string $tag): string
    {
        $language = strtolower(strtok($tag, '-_') ?: $tag);

        return match ($language) {
            'nb', 'nn', 'no' => 'nb',
            'sv' => 'sv',
            default => 'en',
        };
    }

    /** The language's English name for the model prompt, e.g. "Swedish". */
    public static function languageName(string $tag): string
    {
        $language = strtolower(strtok($tag, '-_') ?: $tag);
        $known = ['nb' => 'Norwegian Bokmål', 'nn' => 'Norwegian Nynorsk', 'no' => 'Norwegian Bokmål', 'sv' => 'Swedish', 'da' => 'Danish', 'fi' => 'Finnish', 'en' => 'English', 'de' => 'German'];

        return $known[$language] ?? $tag;
    }
}
