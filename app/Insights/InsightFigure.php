<?php

namespace App\Insights;

/**
 * One figure the insight text may show through a placeholder. The value is already rounded the way the dashboard
 * shows it (one decimal at most), so the text can never show a different number than the page. `direction` is the
 * sign of a change (-1, 0 or 1), and null for a figure that is not a change.
 */
final readonly class InsightFigure
{
    public const PERCENT = 'percent';

    public const POINTS = 'points';

    public const COUNT = 'count';

    public const NUMBER = 'number';

    public function __construct(public string $kind, public float $value, public ?int $direction = null) {}

    /** A share (0 to 1) shown as a percentage. */
    public static function percent(float $share): self
    {
        return new self(self::PERCENT, round($share * 100, 1));
    }

    /** The size of a change between two shares, in percentage points; the direction is kept apart. */
    public static function points(float $now, float $before): self
    {
        $change = round(($now - $before) * 100, 1);

        return new self(self::POINTS, abs($change), $change <=> 0.0);
    }

    public static function count(int $count): self
    {
        return new self(self::COUNT, (float) $count);
    }

    public static function number(float $value): self
    {
        return new self(self::NUMBER, round($value, 1));
    }
}
