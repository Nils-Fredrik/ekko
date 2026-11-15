<?php

namespace App\Metrics;

/**
 * The formulas of docs/spec.md 9 on summed counters from agg_daily. This is the only place they are written;
 * {@see MetricsService} sums the counters in the database and passes them here, and the unit tests check every
 * formula on hand-computed counters.
 *
 * Counters (sums over the rows of the period): responses, brand_mentioned, brand_recommended, sum_position,
 * count_position, list_responses, first_place, sum_sentiment, count_sentiment, own_cited, and
 * competitor_mentions (the summed mentions of the brand's active competitors).
 */
final class Formulas
{
    /**
     * @param  array<string, int|float|string|null>  $totals
     */
    public static function summary(array $totals): MetricsSummary
    {
        $responses = (int) ($totals['responses'] ?? 0);

        return new MetricsSummary(
            responses: $responses,
            visibility: new Metric(self::ratio($totals['brand_mentioned'] ?? 0, $responses), $responses),
            recommendationRate: new Metric(self::ratio($totals['brand_recommended'] ?? 0, $responses), $responses),
            shareOfVoice: new Metric(self::shareOfVoice($totals['brand_mentioned'] ?? 0, $totals['competitor_mentions'] ?? 0), $responses),
            averagePosition: new Metric(self::ratio($totals['sum_position'] ?? 0, $totals['count_position'] ?? 0), (int) ($totals['count_position'] ?? 0)),
            firstPlaceRate: new Metric(self::ratio($totals['first_place'] ?? 0, $totals['list_responses'] ?? 0), (int) ($totals['list_responses'] ?? 0)),
            citationRate: new Metric(self::ratio($totals['own_cited'] ?? 0, $responses), $responses),
            sentiment: new Metric(self::ratio($totals['sum_sentiment'] ?? 0, $totals['count_sentiment'] ?? 0), (int) ($totals['count_sentiment'] ?? 0)),
        );
    }

    /**
     * numerator ÷ denominator, null when the denominator is zero.
     */
    public static function ratio(int|float|string|null $numerator, int|float|string|null $denominator): ?float
    {
        $denominator = (float) $denominator;

        return $denominator > 0 ? (float) $numerator / $denominator : null;
    }

    /**
     * Share of voice: the brand's mentions ÷ (the brand's mentions + the mentions of all active competitors).
     */
    public static function shareOfVoice(int|float|string|null $brandMentions, int|float|string|null $competitorMentions): ?float
    {
        return self::ratio($brandMentions, (float) $brandMentions + (float) $competitorMentions);
    }
}
