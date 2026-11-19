<?php

namespace App\ActionEngine;

use App\ActionEngine\Writing\ActionText;
use App\Enums\ActionType;
use App\Models\Action;
use Illuminate\Support\Str;

/**
 * The template text of an action and its evidence sentence (lang action_items), used before the model has
 * worded the action and whenever it cannot. The placeholders are the candidate's text params, which are figures
 * and names read from the database (stored in evidence.text_params).
 */
final class ActionTemplates
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function text(ActionType $type, array $params, string $locale): ActionText
    {
        $params = self::localized($params, $locale);
        $base = "action_items.templates.{$type->value}";
        $steps = trans("{$base}.steps", [], $locale);
        $stepTexts = [];
        foreach (is_array($steps) ? array_keys($steps) : [] as $index) {
            $stepTexts[] = (string) trans("{$base}.steps.{$index}", $params, $locale);
        }

        return new ActionText(
            // A name can be long (an unknown brand up to 255 characters); the title column holds 255.
            Str::limit((string) trans("{$base}.title", $params, $locale), 250),
            (string) trans("{$base}.description", $params, $locale),
            $stepTexts,
        );
    }

    /**
     * The evidence in one sentence, e.g. «bransjeside.no ble sitert i 14 svar …» (docs/spec.md 11.3), in the given
     * locale. Null when there is nothing generated to fill the template: a user's own action stores a type without
     * text params, and rendering it would leave placeholders such as :domain.
     */
    public static function evidenceSentence(Action $action, string $locale): ?string
    {
        if ($action->type === null) {
            return null;
        }
        $params = $action->evidence['text_params'] ?? null;
        if (! is_array($params) || $params === []) {
            return null;
        }
        $key = "action_items.evidence.{$action->type->value}";
        if ($action->type === ActionType::SourceGap && (string) ($params['competitor'] ?? '') === '') {
            $key .= '_without_competitor';
        }

        return (string) trans($key, self::localized($params, $locale), $locale);
    }

    /**
     * The fact_correction parts that depend on the source: :find and :fix for the steps, and :source as a phrase
     * when no source is known (docs/spec.md 11.6: fix an own page, otherwise ask the publisher).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function withFactParts(array $params, string $locale): array
    {
        $kind = in_array($params['source_kind'], ['own', 'other'], true) ? (string) $params['source_kind'] : 'unknown';
        $source = (string) ($params['source'] ?? '');
        if ($source === '') {
            $kind = 'unknown';
            $params['source'] = (string) trans('action_items.fact.source_unknown', [], $locale);
        }
        $params['find'] = (string) trans($kind === 'unknown' ? 'action_items.fact.find_unknown' : 'action_items.fact.find_known', ['source' => $source], $locale);
        $params['fix'] = (string) trans("action_items.fact.fix_{$kind}", [], $locale);

        return $params;
    }

    /**
     * Adds :market, the name of the country code in :country in the locale («Sverige», "Sweden").
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function localized(array $params, string $locale): array
    {
        if (array_key_exists('source_kind', $params)) {
            $params = self::withFactParts($params, $locale);
        }
        $country = $params['country'] ?? null;
        if (is_string($country) && $country !== '') {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$country, $locale) : '';
            $params['market'] = $name !== '' && $name !== $country ? $name : $country;
        }

        return $params;
    }
}
