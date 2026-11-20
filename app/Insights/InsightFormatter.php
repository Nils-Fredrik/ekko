<?php

namespace App\Insights;

/**
 * Writes a figure the way the dashboard shows it: the locale's decimal separator, one decimal at most.
 * The model never writes a figure; this is the only place one is turned into text (ADR 0038).
 */
final class InsightFormatter
{
    public static function format(InsightFigure $figure, string $locale): string
    {
        $number = self::number($figure->value, $locale);

        return match ($figure->kind) {
            InsightFigure::PERCENT => $locale === 'en' ? $number.'%' : $number."\u{00A0}%",
            InsightFigure::POINTS => trans('insights.points', ['n' => $number], $locale),
            default => $number,
        };
    }

    public static function number(float $value, string $locale): string
    {
        $text = number_format($value, 1, $locale === 'en' ? '.' : ',', $locale === 'en' ? ',' : "\u{00A0}");
        $decimal = $locale === 'en' ? '.0' : ',0';

        return str_ends_with($text, $decimal) ? substr($text, 0, -2) : $text;
    }
}
