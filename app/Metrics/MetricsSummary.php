<?php

namespace App\Metrics;

/**
 * The headline metrics of docs/spec.md 9 for one filter. Each {@see Metric} carries its own n: answers for
 * visibility, recommendation rate, share of voice and citation rate; answers with a list position for the
 * average position; list answers for the first-place rate; and mentions with a sentiment for sentiment.
 */
final class MetricsSummary
{
    public function __construct(
        public readonly int $responses,
        public readonly Metric $visibility,
        public readonly Metric $recommendationRate,
        public readonly Metric $shareOfVoice,
        public readonly Metric $averagePosition,
        public readonly Metric $firstPlaceRate,
        public readonly Metric $citationRate,
        public readonly Metric $sentiment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'responses' => $this->responses,
            'visibility' => $this->visibility->toArray(),
            'recommendation_rate' => $this->recommendationRate->toArray(),
            'share_of_voice' => $this->shareOfVoice->toArray(),
            'average_position' => $this->averagePosition->toArray(),
            'first_place_rate' => $this->firstPlaceRate->toArray(),
            'citation_rate' => $this->citationRate->toArray(),
            'sentiment' => $this->sentiment->toArray(),
        ];
    }
}
