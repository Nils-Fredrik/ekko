<?php

namespace App\Metrics;

use App\Models\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the sources page (docs/ui-forbedring.md 7.5, U08) that the source aggregates do not hold. They are
 * read from the extracted answers of the filter, like {@see MetricsService::sourceGapDetail()}, and are bounded by
 * the domains the page shows.
 */
trait SourcePageMetrics
{
    /**
     * For each domain: how often it is cited in the answers that name the brand, against how often it is cited in
     * the answers that name a competitor of the brand and not the brand (the source gap). The rate on each side has
     * its own n: all answers that name the brand, and all answers that name only competitors. A citation of the
     * domain as the brand's own never counts as a gap, the same rule as the aggregator and sourceGapDetail.
     *
     * @param  list<string>  $domains
     * @return array{own_n: int, competitor_n: int, rows: array<string, array{own: int, competitors: int}>}
     */
    public function sourceGapComparison(MetricsFilter $filter, array $domains): array
    {
        $this->refuseSourcePromptLimits($filter);
        $domains = array_values(array_unique($domains));
        $query = $this->extractedAnswers($filter)->toBase()->select('responses.id');
        $placeholders = $domains === [] ? 'NULL' : implode(', ', array_fill(0, count($domains), '?'));
        $row = DB::selectOne(<<<SQL
            WITH answers AS ({$query->toSql()}),
            flags AS (
                SELECT answers.id,
                    EXISTS (SELECT 1 FROM mentions m WHERE m.response_id = answers.id
                        AND m.entity_type = 'brand' AND m.entity_id = ?) AS brand,
                    EXISTS (SELECT 1 FROM mentions m JOIN competitors c ON c.id = m.entity_id
                        WHERE m.response_id = answers.id AND m.entity_type = 'competitor' AND c.brand_id = ?) AS competitor
                FROM answers
            ),
            domains AS (
                SELECT ci.domain,
                    count(DISTINCT flags.id) FILTER (WHERE flags.brand) AS own,
                    count(DISTINCT flags.id) FILTER (WHERE flags.competitor AND NOT flags.brand AND NOT EXISTS (
                        SELECT 1 FROM citations o WHERE o.response_id = flags.id AND o.domain = ci.domain AND o.ownership = 'own'
                    )) AS competitors
                FROM flags
                JOIN citations ci ON ci.response_id = flags.id
                WHERE ci.domain IN ({$placeholders})
                GROUP BY ci.domain
            )
            SELECT (SELECT count(*) FROM flags WHERE brand) AS own_n,
                (SELECT count(*) FROM flags WHERE competitor AND NOT brand) AS competitor_n,
                (SELECT jsonb_agg(to_jsonb(domains) ORDER BY domain) FROM domains) AS rows
            SQL, [...$query->getBindings(), $filter->brandId, $filter->brandId, ...$domains]);

        if ($row === null) {
            throw new \LogicException('Source gap comparison returned no result.');
        }

        $rows = [];
        foreach (json_decode((string) ($row->rows ?? '[]'), true, flags: JSON_THROW_ON_ERROR) ?? [] as $part) {
            $rows[(string) $part['domain']] = ['own' => (int) $part['own'], 'competitors' => (int) $part['competitors']];
        }

        return ['own_n' => (int) $row->own_n, 'competitor_n' => (int) $row->competitor_n, 'rows' => $rows];
    }

    /**
     * The extracted answers of the filter that cite the domain, newest first, for the drill-down from a domain.
     *
     * @return Builder<Response>
     */
    public function citingAnswers(MetricsFilter $filter, string $domain): Builder
    {
        $this->refuseSourcePromptLimits($filter);

        return $this->extractedAnswers($filter)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('citations')
                ->whereColumn('citations.response_id', 'responses.id')->where('citations.domain', $domain))
            ->select('responses.*')
            ->orderByDesc('responses.collected_at')
            ->orderByDesc('responses.id');
    }
}
