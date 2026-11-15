<?php

namespace App\Metrics;

/**
 * One metric value with the number of observations it rests on (docs/spec.md 9: n is always shown next to the
 * value, and values with n < 10 are shown faded). A rate is a fraction from 0 to 1, not a percentage. The
 * value is null when there is nothing to compute it from (n = 0).
 */
final class Metric
{
    /** Below this many observations a value is shown faded (docs/spec.md 9). */
    public const LOW_SAMPLE = 10;

    public function __construct(public readonly ?float $value, public readonly int $n) {}

    public function isLowSample(): bool
    {
        return $this->n < self::LOW_SAMPLE;
    }

    /**
     * @return array{value: float|null, n: int, low_sample: bool}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'n' => $this->n, 'low_sample' => $this->isLowSample()];
    }
}
