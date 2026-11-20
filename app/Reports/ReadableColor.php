<?php

namespace App\Reports;

/** Darkens a hex color until it clears WCAG AA against another color. */
final class ReadableColor
{
    public const float MINIMUM = 4.6;

    public static function against(string $color, string $other, float $minimum = self::MINIMUM): string
    {
        $start = self::normalize($color);
        $fixed = self::normalize($other);
        if ($start === null || $fixed === null || self::contrast($start, $fixed) >= $minimum) {
            return $start ?? $color;
        }

        $low = 0.0;
        $high = 1.0;
        $best = self::mix($start, '#000000', 1.0);
        for ($step = 0; $step < 16; $step++) {
            $mid = ($low + $high) / 2;
            $mixed = self::mix($start, '#000000', $mid);
            if (self::contrast($mixed, $fixed) >= $minimum) {
                $best = $mixed;
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return $best;
    }

    public static function contrast(string $foreground, string $background): float
    {
        $lighter = max(self::luminance($foreground), self::luminance($background));
        $darker = min(self::luminance($foreground), self::luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function normalize(string $hex): ?string
    {
        $hex = strtolower(trim($hex));
        if (preg_match('/\A#([0-9a-f]{6})\z/', $hex) !== 1) {
            return null;
        }

        return $hex;
    }

    private static function mix(string $from, string $to, float $amount): string
    {
        $mixed = '';
        for ($channel = 1; $channel <= 5; $channel += 2) {
            $start = hexdec(substr($from, $channel, 2));
            $end = hexdec(substr($to, $channel, 2));
            $mixed .= sprintf('%02x', (int) round($start + ($end - $start) * $amount));
        }

        return '#'.$mixed;
    }

    private static function luminance(string $hex): float
    {
        $channels = [];
        foreach (str_split(substr(self::normalize($hex) ?? '#000000', 1), 2) as $pair) {
            $value = hexdec($pair) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
