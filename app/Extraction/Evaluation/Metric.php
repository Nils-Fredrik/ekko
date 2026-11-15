<?php

namespace App\Extraction\Evaluation;

/**
 * Precision and hit rate (recall) of one predicted value against the gold set. "Positive" is true for
 * is_recommended and a non-null number for list_position; for list_position a prediction is correct only
 * when the number is the expected one.
 */
final class Metric
{
    public function __construct(
        public int $truePositives = 0,
        public int $predicted = 0,
        public int $expected = 0,
    ) {}

    public function precision(): ?float
    {
        return $this->predicted === 0 ? null : $this->truePositives / $this->predicted;
    }

    public function hitRate(): ?float
    {
        return $this->expected === 0 ? null : $this->truePositives / $this->expected;
    }
}
