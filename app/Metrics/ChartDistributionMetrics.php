<?php

namespace App\Metrics;

use App\Models\AggSourceDaily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Additional distribution metrics used by the charts in U03 (ADR 0037).
 * Each query checks completeness in the same database snapshot as its values.
 */
trait ChartDistributionMetrics
{
    /**
     * Sentiment-bearing mentions per entity, classified by the sign of the
     * original sentiment. Historical NULL distributions mean unavailable data,
     * not zero mentions; no partial results are returned in that case.
     *
     * @return array{complete: bool, rows: list<array{key: string, negative: int, neutral: int, positive: int, n: int, low_sample: bool}>|null}
     */
    public function sentimentDistribution(MetricsFilter $filter): array
    {
        $query = $this->dailyQuery($filter)->toBase()->select('agg_daily.sentiment_distribution');
        $sql = $query->toSql();
        $row = DB::selectOne(<<<SQL
            WITH filtered AS ({$sql}),
            parts AS (
                SELECT 'own' AS key,
                    coalesce(sum((sentiment_distribution->'own'->>'negative')::bigint), 0) AS negative,
                    coalesce(sum((sentiment_distribution->'own'->>'neutral')::bigint), 0) AS neutral,
                    coalesce(sum((sentiment_distribution->'own'->>'positive')::bigint), 0) AS positive
                FROM filtered
                UNION ALL
                SELECT 'competitor:' || item.key AS key,
                    sum((item.value->>'negative')::bigint) AS negative,
                    sum((item.value->>'neutral')::bigint) AS neutral,
                    sum((item.value->>'positive')::bigint) AS positive
                FROM filtered
                CROSS JOIN LATERAL jsonb_each(coalesce(sentiment_distribution->'competitors', '{}'::jsonb)) item
                JOIN competitors c ON c.id::text = item.key AND c.brand_id = ? AND c.tenant_id = ? AND c.is_active
                GROUP BY item.key
            )
            SELECT (SELECT count(*) FROM filtered WHERE sentiment_distribution IS NULL) AS missing,
                (SELECT jsonb_agg(to_jsonb(parts) ORDER BY key) FROM parts) AS rows
            SQL, [...$query->getBindings(), $filter->brandId, current_tenant_id()]);

        if ($row === null) {
            throw new \LogicException('Sentiment distribution query returned no result.');
        }
        if ((int) $row->missing > 0) {
            return ['complete' => false, 'rows' => null];
        }

        $rows = json_decode((string) $row->rows, true, flags: JSON_THROW_ON_ERROR) ?? [];

        return ['complete' => true, 'rows' => array_map(function (array $part): array {
            $negative = (int) $part['negative'];
            $neutral = (int) $part['neutral'];
            $positive = (int) $part['positive'];
            $n = $negative + $neutral + $positive;

            return ['key' => (string) $part['key'], 'negative' => $negative, 'neutral' => $neutral,
                'positive' => $positive, 'n' => $n, 'low_sample' => $n < Metric::LOW_SAMPLE];
        }, $rows)];
    }

    /**
     * Counts each citation by domain and its recorded ownership/type. n is the
     * number of categorized citations for that domain, across its categories.
     * Source aggregates cannot be filtered by prompt dimensions.
     *
     * @return array{complete: bool, rows: list<array{domain: string, ownership: string, source_type: string, citations: int, n: int, low_sample: bool}>|null}
     */
    public function citationDistribution(MetricsFilter $filter): array
    {
        $this->refuseSourcePromptLimits($filter);
        $query = AggSourceDaily::query()
            ->where('brand_id', $filter->brandId)
            ->whereBetween('date', [$filter->from, $filter->to])
            ->when($filter->marketIds !== [], fn (Builder $q) => $q->whereIn('market_id', $filter->marketIds))
            ->when($filter->engineKeys !== [], fn (Builder $q) => $q->whereIn('engine_key', $filter->engineKeys))
            ->toBase()->select(['domain', 'citation_distribution']);
        $sql = $query->toSql();
        $row = DB::selectOne(<<<SQL
            WITH filtered AS ({$sql}),
            categories AS (
                SELECT domain, owner.key AS ownership, type.key AS source_type, sum(type.value::bigint) AS citations
                FROM filtered
                CROSS JOIN LATERAL jsonb_each(coalesce(citation_distribution, '{}'::jsonb)) owner
                CROSS JOIN LATERAL jsonb_each_text(owner.value) type
                GROUP BY domain, owner.key, type.key
            ),
            with_n AS (
                SELECT *, sum(citations) OVER (PARTITION BY domain) AS n FROM categories
            )
            SELECT (SELECT count(*) FROM filtered WHERE citation_distribution IS NULL) AS missing,
                (SELECT jsonb_agg(to_jsonb(with_n) ORDER BY domain, ownership, source_type) FROM with_n) AS rows
            SQL, $query->getBindings());

        if ($row === null) {
            throw new \LogicException('Citation distribution query returned no result.');
        }
        if ((int) $row->missing > 0) {
            return ['complete' => false, 'rows' => null];
        }

        $rows = json_decode((string) ($row->rows ?? '[]'), true, flags: JSON_THROW_ON_ERROR);

        return ['complete' => true, 'rows' => array_map(fn (array $part): array => [
            'domain' => (string) $part['domain'], 'ownership' => (string) $part['ownership'],
            'source_type' => (string) $part['source_type'], 'citations' => (int) $part['citations'],
            'n' => (int) $part['n'], 'low_sample' => (int) $part['n'] < Metric::LOW_SAMPLE,
        ], $rows)];
    }
}
