<?php

namespace App\Dashboard;

use App\Models\Engine;

/**
 * User-facing labels for stored identifiers (docs/spec.md 14.1). The identifiers stay in the
 * database and in query parameters. The words come from lang/{locale}/app.php.
 */
final class DashboardLabels
{
    public static function status(string $status): string
    {
        return self::known("app.brand_status.{$status}", 'app.brand_status.unknown');
    }

    public static function alertType(string $type): string
    {
        return self::known("app.alert_types.{$type}", 'app.alert_types.unknown');
    }

    public static function intent(string $intent): string
    {
        return self::known("app.intents.{$intent}", 'app.intents.unknown');
    }

    /**
     * engines has no name column. Known keys are translated, otherwise the family
     * (chatgpt, gemini, google_aio, perplexity, claude).
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function engines(array $keys): array
    {
        $families = Engine::query()->whereIn('key', $keys)->pluck('family', 'key');
        $labels = [];

        foreach ($keys as $key) {
            $family = $families->get($key);
            $labels[$key] = self::engine($key, is_string($family) ? $family : null);
        }

        return $labels;
    }

    public static function engine(string $key, ?string $family = null): string
    {
        $specific = "app.engines.{$key}";

        if (__($specific) !== $specific) {
            return __($specific);
        }

        if ($family !== null) {
            $familyKey = "app.engines.families.{$family}";

            if (__($familyKey) !== $familyKey) {
                return __($familyKey);
            }
        }

        return __('app.engines.unknown');
    }

    private static function known(string $key, string $fallback): string
    {
        $label = __($key);

        return $label === $key ? __($fallback) : $label;
    }
}
