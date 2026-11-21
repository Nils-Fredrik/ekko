<?php

namespace App\Dashboard;

use App\Metrics\Formulas;
use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Competitor;

/**
 * The data of the competitors page (docs/ui-forbedring.md 7.4, U07): the rank of the brand and its active
 * competitors over time, a table with visibility, change against the comparison period, rank and sentiment per
 * brand, and the sentiment distribution. Every figure comes from MetricsService; this class only joins them.
 *
 * Competitors keep their fixed color place (`color_index`, Competitor::colorIndexes): the active competitors in
 * position order, the same order the dashboard (U05), the sources page (U08) and reports use, so a competitor has
 * the same color on every page (docs/ux-arbeidsflate.md 6.3).
 */
final class CompetitorPage
{
    /** Periods longer than this are ranked per ISO week, so the rank chart stays readable. */
    public const DAILY_UP_TO = 31;

    public function __construct(private readonly MetricsService $metrics) {}

    /** The rank chart's grain. The page has no grouping control (U11), so it follows the period length. */
    public static function grain(DashboardPeriod $period): string
    {
        return $period->days > self::DAILY_UP_TO ? 'week' : 'day';
    }

    /**
     * @return array{grain: string, ranking: list<array<string, mixed>>, table: list<array<string, mixed>>, sentiment: array{complete: bool, rows: list<array<string, mixed>>|null}}
     */
    public function chartData(MetricsFilter $filter, MetricsFilter $previous, string $grain): array
    {
        $sentiment = $this->metrics->sentimentDistribution($filter);

        return [
            'grain' => $grain,
            'ranking' => $this->metrics->competitorVisibilitySeries($filter, $grain),
            'table' => $this->table($filter, $previous, $sentiment),
            'sentiment' => $sentiment,
        ];
    }

    /**
     * One row for the brand and each active competitor, best rank first. Visibility is the share of the period's
     * answers that name the entity (docs/spec.md 9), its change is in the same unit against the comparison period,
     * and the rank is the place among the brand and its active competitors by visibility, shared on ties.
     * Position (list_position) is only aggregated for the brand (ADR 0016), so the rank is the comparable place.
     *
     * @param  array{complete: bool, rows: list<array{key: string, negative: int, neutral: int, positive: int, n: int, low_sample: bool}>|null}  $sentiment
     * @return list<array<string, mixed>>
     */
    private function table(MetricsFilter $filter, MetricsFilter $previous, array $sentiment): array
    {
        $current = $this->metrics->voiceParts($filter);
        $before = collect($this->metrics->voiceParts($previous))->keyBy('id');
        $ranks = self::ranks($current);
        $previousRanks = self::ranks($before->values()->all());
        $sentiments = collect($sentiment['rows'] ?? [])->keyBy('key');
        $colors = Competitor::colorIndexes($filter->brandId);

        $rows = [];
        foreach ($current as $part) {
            $own = $part['id'] === 'brand';
            $visibility = new Metric(Formulas::ratio($part['mentions'], $part['n']), $part['n']);
            $old = $before->get($part['id']);
            $previousVisibility = $old === null ? new Metric(null, 0) : new Metric(Formulas::ratio($old['mentions'], $old['n']), $old['n']);
            $mood = $sentiments->get($own ? 'own' : 'competitor:'.$part['id']);

            $rows[] = [
                'key' => $part['id'],
                'id' => $own ? null : (int) $part['id'],
                'name' => $part['name'],
                'own' => $own,
                'color_index' => $own ? null : ($colors[(int) $part['id']] ?? null),
                'visibility' => $visibility->toArray(),
                'previous_visibility' => $previousVisibility->toArray(),
                'change' => $visibility->value === null || $previousVisibility->value === null
                    ? null : $visibility->value - $previousVisibility->value,
                'rank' => $ranks[$part['id']] ?? null,
                'previous_rank' => $previousRanks[$part['id']] ?? null,
                'sentiment' => $sentiment['complete'] ? self::shares($mood) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['rank'] ?? PHP_INT_MAX, ! $a['own'], (string) $a['name']]
            <=> [$b['rank'] ?? PHP_INT_MAX, ! $b['own'], (string) $b['name']]);

        return $rows;
    }

    /**
     * Rank by mentions within the period (the same as by visibility, since n is shared), with a shared rank on
     * ties, as in {@see MetricsService::competitorVisibilitySeries()}. No rank without answers.
     *
     * @param  list<array{id: string, mentions: int, n: int}>  $parts
     * @return array<string, int>
     */
    private static function ranks(array $parts): array
    {
        if ($parts === [] || $parts[0]['n'] === 0) {
            return [];
        }
        $counts = array_column($parts, 'mentions');
        rsort($counts, SORT_NUMERIC);
        $first = [];
        foreach ($counts as $index => $count) {
            $first[$count] ??= $index + 1;
        }
        $ranks = [];
        foreach ($parts as $part) {
            $ranks[$part['id']] = $first[$part['mentions']];
        }

        return $ranks;
    }

    /**
     * @param  array{negative: int, neutral: int, positive: int, n: int, low_sample: bool}|null  $row
     * @return array{negative: float|null, neutral: float|null, positive: float|null, n: int, low_sample: bool}
     */
    private static function shares(?array $row): array
    {
        $n = (int) ($row['n'] ?? 0);

        return [
            'negative' => Formulas::ratio((int) ($row['negative'] ?? 0), $n),
            'neutral' => Formulas::ratio((int) ($row['neutral'] ?? 0), $n),
            'positive' => Formulas::ratio((int) ($row['positive'] ?? 0), $n),
            'n' => $n,
            'low_sample' => $n < Metric::LOW_SAMPLE,
        ];
    }
}
