<?php

namespace App\Metrics;

use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Competitor;
use App\Models\Mention;
use App\Models\ModelChange;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The only place the formulas of docs/spec.md 9 live (ADR 0012). Alerts, the dashboard, PDF reports, the REST API
 * and the MCP server call this service and never sum agg_daily themselves.
 *
 * It reads agg_daily, agg_source_daily, bot_hits_daily and ai_referrals_daily for the tenant in the context, so a
 * caller must have set the tenant (and, in jobs, row-level security). Weekly and monthly figures are sums over the
 * days of the period, computed in the query and never stored (6.4). Dates are the tenant's local days.
 */
final class MetricsService
{
    use ChartDistributionMetrics;
    use ChartTrafficMetrics;

    /** Grain names for {@see series()}. */
    public const GRAINS = ['day', 'week', 'month'];

    /** Additive counters used by every summary, before Formulas calculates ratios. */
    private const COUNTERS = [
        'responses', 'brand_mentioned', 'brand_recommended', 'sum_position', 'count_position',
        'list_responses', 'first_place', 'sum_sentiment', 'count_sentiment', 'own_cited',
    ];

    /** Dimensions for {@see breakdown()}: name => column in agg_daily or prompts. */
    private const DIMENSIONS = [
        'market' => 'agg_daily.market_id',
        'engine' => 'agg_daily.engine_key',
        'prompt' => 'agg_daily.prompt_id',
        'topic' => 'prompts.topic_id',
        'intent' => 'prompts.intent',
        'funnel_stage' => 'prompts.funnel_stage',
    ];

    /**
     * Visibility, recommendation rate, share of voice, average position, first-place rate, citation rate and
     * sentiment for the filter, with n.
     */
    public function summary(MetricsFilter $filter): MetricsSummary
    {
        $row = $this->totalsQuery($filter)->first();

        return Formulas::summary($row === null ? [] : (array) $row);
    }

    /**
     * The brand dashboard's current period in one aggregate scan. GROUPING SETS sums the same
     * filtered counters for the entire period, each day and each engine; ratios are never averaged.
     * No result is cached, so changing tenant context, filters or active competitors takes effect immediately.
     *
     * @return array{summary: MetricsSummary, series: list<array{period: string, summary: MetricsSummary}>, engines: array<string, MetricsSummary>, voice: list<array{id: string, name: string|null, mentions: int, share: float|null, n: int}>}
     */
    public function dashboard(MetricsFilter $filter): array
    {
        $competitors = Competitor::query()
            ->where('brand_id', $filter->brandId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $query = $this->dailyQuery($filter)->toBase()->selectRaw(
            'agg_daily.date as period, agg_daily.engine_key, grouping(agg_daily.date, agg_daily.engine_key) as grouping_key, '.$this->counterColumns(),
        );
        $this->selectCompetitorCounters($query, $competitors);
        $rows = $query->groupByRaw('GROUPING SETS ((), (agg_daily.date), (agg_daily.engine_key))')
            ->orderBy('period')->orderBy('engine_key')->get();

        $result = ['summary' => Formulas::summary([]), 'series' => [], 'engines' => [], 'voice' => []];
        foreach ($rows as $row) {
            $totals = (array) $row;
            $totals['competitor_mentions'] = 0;
            foreach ($competitors as $index => $competitor) {
                $totals['competitor_mentions'] += (int) $totals["competitor_{$index}"];
            }
            $summary = Formulas::summary($totals);
            if ((int) $row->grouping_key === 3) {
                // The empty grouping set returns a zero total even when no daily rows match.
                $result['summary'] = $summary;
                $result['voice'] = $this->voiceFromTotals($totals, $competitors);
            } elseif ((int) $row->grouping_key === 1) {
                $result['series'][] = ['period' => substr((string) $row->period, 0, 10), 'summary' => $summary];
            } else {
                $result['engines'][(string) $row->engine_key] = $summary;
            }
        }

        return $result;
    }

    public function visibility(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->visibility;
    }

    public function recommendationRate(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->recommendationRate;
    }

    public function shareOfVoice(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->shareOfVoice;
    }

    public function averagePosition(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->averagePosition;
    }

    public function firstPlaceRate(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->firstPlaceRate;
    }

    public function citationRate(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->citationRate;
    }

    public function sentiment(MetricsFilter $filter): Metric
    {
        return $this->summary($filter)->sentiment;
    }

    /**
     * Three independently measured quantities, not a conversion rate: bot requests, answers
     * citing an owned domain, and AI-referred sessions. Missing traffic observations remain null.
     *
     * @return array<string, array{value: int|null, n: int, low_sample: bool}>
     */
    public function trafficFunnel(MetricsFilter $filter): array
    {
        $bots = $this->botHitsSeries($filter);
        $traffic = $this->aiTrafficSeries($filter);
        $row = (array) ($this->totalsQuery($filter)->first() ?? []);
        $responses = (int) ($row['responses'] ?? 0);
        $hits = $bots === [] ? null : array_sum(array_column($bots, 'hits'));
        $sessions = $traffic === [] ? null : array_sum(array_column($traffic, 'sessions'));

        return [
            'fetched' => ['value' => $hits, 'n' => $hits ?? 0, 'low_sample' => ($hits ?? 0) < Metric::LOW_SAMPLE],
            'cited' => ['value' => $responses === 0 ? null : (int) ($row['own_cited'] ?? 0), 'n' => $responses, 'low_sample' => $responses < Metric::LOW_SAMPLE],
            'visits' => ['value' => $sessions, 'n' => $sessions ?? 0, 'low_sample' => ($sessions ?? 0) < Metric::LOW_SAMPLE],
        ];
    }

    /**
     * The summary per day, week (ISO, Monday first) or month in the period, oldest first. Periods without
     * answers are left out. The first and last period are partial when the filter does not start or end on a
     * period boundary.
     *
     * @return list<array{period: string, summary: MetricsSummary}>
     */
    public function series(MetricsFilter $filter, string $grain = 'day'): array
    {
        if (! in_array($grain, self::GRAINS, true)) {
            throw new InvalidArgumentException("Unknown grain: {$grain}");
        }

        $period = $grain === 'day' ? 'agg_daily.date' : "date_trunc('{$grain}', agg_daily.date)::date";
        $rows = $this->totalsQuery($filter, groupBy: $period, groupAs: 'period')->orderBy('period')->get();

        return $rows->map(fn (object $row): array => [
            'period' => substr((string) $row->period, 0, 10),
            'summary' => Formulas::summary((array) $row),
        ])->all();
    }

    /**
     * Visibility and share of voice for the brand and active competitors in each period. A point's sample is
     * the number of answers, not the number of mentions. Ratios are computed after summing all daily rows.
     * Ranking is by visibility within the period, with a shared rank for ties and null when no answers exist.
     *
     * @return list<array{period: string, entities: list<array{id: string, name: string|null, visibility: array{value: float|null, n: int, low_sample: bool}, share_of_voice: array{value: float|null, n: int, low_sample: bool}, rank: int|null}>}>
     */
    public function competitorVisibilitySeries(MetricsFilter $filter, string $grain = 'day'): array
    {
        $period = $this->graphPeriod($grain);
        $competitors = Competitor::query()->where('brand_id', $filter->brandId)->where('is_active', true)
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);
        $query = $this->dailyQuery($filter)->toBase()->selectRaw(
            "{$period} as period, sum(agg_daily.responses) as responses, sum(agg_daily.brand_mentioned) as brand_mentioned",
        );
        $this->selectCompetitorCounters($query, $competitors);
        $rows = $query->groupByRaw($period)->orderBy('period')->get();
        $result = [];
        foreach ($rows as $row) {
            $n = (int) $row->responses;
            $counts = [['id' => 'brand', 'name' => null, 'count' => (int) $row->brand_mentioned]];
            foreach ($competitors as $index => $competitor) {
                $counts[] = ['id' => (string) $competitor->id, 'name' => $competitor->name, 'count' => (int) $row->{"competitor_{$index}"}];
            }
            $totalMentions = array_sum(array_column($counts, 'count'));
            $entities = [];
            foreach ($counts as $entity) {
                $rank = $n === 0 ? null : 1 + count(array_filter($counts, fn (array $other): bool => $other['count'] > $entity['count']));
                $entities[] = [
                    'id' => $entity['id'], 'name' => $entity['name'],
                    'visibility' => (new Metric(Formulas::ratio($entity['count'], $n), $n))->toArray(),
                    'share_of_voice' => (new Metric(Formulas::ratio($entity['count'], $totalMentions), $n))->toArray(),
                    'rank' => $rank,
                ];
            }
            $result[] = ['period' => substr((string) $row->period, 0, 10), 'entities' => $entities];
        }

        return $result;
    }

    /**
     * Visibility per prompt and engine, for the heatmap. Empty cells are omitted rather than inventing zeroes.
     *
     * @return list<array{prompt_id: int, engine_key: string, visibility: array{value: float|null, n: int, low_sample: bool}}>
     */
    public function visibilityByPromptEngine(MetricsFilter $filter): array
    {
        return $this->dailyQuery($filter)->toBase()
            ->selectRaw('agg_daily.prompt_id, agg_daily.engine_key, sum(agg_daily.responses) as responses, sum(agg_daily.brand_mentioned) as mentioned')
            ->groupBy('agg_daily.prompt_id', 'agg_daily.engine_key')->orderBy('agg_daily.prompt_id')->orderBy('agg_daily.engine_key')
            ->get()->map(fn (object $row): array => [
                'prompt_id' => (int) $row->prompt_id, 'engine_key' => (string) $row->engine_key,
                'visibility' => (new Metric(Formulas::ratio($row->mentioned, $row->responses), (int) $row->responses))->toArray(),
            ])->all();
    }

    /**
     * Visibility series for the small multiples, keeping the underlying n for every period and engine.
     *
     * @return list<array{period: string, engine_key: string, visibility: array{value: float|null, n: int, low_sample: bool}}>
     */
    public function visibilityByEngineSeries(MetricsFilter $filter, string $grain = 'day'): array
    {
        $period = $this->graphPeriod($grain);

        return $this->dailyQuery($filter)->toBase()
            ->selectRaw("{$period} as period, agg_daily.engine_key, sum(agg_daily.responses) as responses, sum(agg_daily.brand_mentioned) as mentioned")
            ->groupByRaw($period)->groupBy('agg_daily.engine_key')->orderBy('period')->orderBy('agg_daily.engine_key')
            ->get()->map(fn (object $row): array => [
                'period' => substr((string) $row->period, 0, 10), 'engine_key' => (string) $row->engine_key,
                'visibility' => (new Metric(Formulas::ratio($row->mentioned, $row->responses), (int) $row->responses))->toArray(),
            ])->all();
    }

    /**
     * The last 14 days within the selected period: whether at least one answer mentioned the brand, plus the
     * number of answers. Missing days have no point and days with zero answers have a null value.
     *
     * @return list<array{prompt_id: int, engine_key: string, period: string, value: float|null, n: int, low_sample: bool}>
     */
    public function stabilityDots(MetricsFilter $filter): array
    {
        $start = max($filter->from, CarbonImmutable::parse($filter->to)->subDays(13)->format('Y-m-d'));

        return $this->dailyQuery($filter)->where('agg_daily.date', '>=', $start)->toBase()
            ->selectRaw('agg_daily.prompt_id, agg_daily.engine_key, agg_daily.date, sum(agg_daily.responses) as responses, sum(agg_daily.brand_mentioned) as mentioned')
            ->groupBy('agg_daily.prompt_id', 'agg_daily.engine_key', 'agg_daily.date')
            ->orderBy('agg_daily.prompt_id')->orderBy('agg_daily.engine_key')->orderBy('agg_daily.date')
            ->get()->map(function (object $row): array {
                $n = (int) $row->responses;
                $value = $n === 0 ? null : ((int) $row->mentioned > 0 ? 1.0 : 0.0);

                return [
                    'prompt_id' => (int) $row->prompt_id, 'engine_key' => (string) $row->engine_key,
                    'period' => substr((string) $row->date, 0, 10), ...(new Metric($value, $n))->toArray(),
                ];
            })->all();
    }

    private function graphPeriod(string $grain): string
    {
        if (! in_array($grain, self::GRAINS, true)) {
            throw new InvalidArgumentException("Unknown grain: {$grain}");
        }

        return $grain === 'day' ? 'agg_daily.date' : "date_trunc('{$grain}', agg_daily.date)::date";
    }

    /**
     * The summary per value of a dimension (market, engine, prompt, topic, intent or funnel_stage), keyed by the
     * value (the topic key is "" for prompts without a topic).
     *
     * @return array<string, MetricsSummary>
     */
    public function breakdown(MetricsFilter $filter, string $dimension): array
    {
        $column = self::DIMENSIONS[$dimension] ?? throw new InvalidArgumentException("Unknown dimension: {$dimension}");

        $rows = $this->totalsQuery($filter, groupBy: $column, groupAs: 'dimension_value', joinPrompts: str_starts_with($column, 'prompts.'))
            ->orderBy('dimension_value')->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->dimension_value] = Formulas::summary((array) $row);
        }

        return $result;
    }

    /**
     * The brand against its active competitors (docs/spec.md 9, 15.2 compare_competitors): for each competitor the
     * answers that name it, its visibility (those answers ÷ the brand's answers) and its share of voice (its
     * mentions ÷ the mentions of the brand and all active competitors, the same denominator as the brand's share
     * of voice). Most mentioned first. Position and recommendation rate are only aggregated for the brand.
     *
     * @return list<array{competitor_id: int, name: string, mentions: int, visibility: Metric, share_of_voice: Metric}>
     */
    public function competitorComparison(MetricsFilter $filter): array
    {
        $competitors = Competitor::query()->where('brand_id', $filter->brandId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        if ($competitors->isEmpty()) {
            return [];
        }

        $mentions = $this->dailyQuery($filter)
            ->toBase()
            ->crossJoin(DB::raw("jsonb_each_text(case when jsonb_typeof(agg_daily.competitor_mentions) = 'object' then agg_daily.competitor_mentions else '{}'::jsonb end) as e"))
            ->whereIn(DB::raw('e.key'), $competitors->keys()->map(fn ($id) => (string) $id)->all())
            ->groupBy(DB::raw('e.key'))
            ->selectRaw('e.key as competitor_id, sum(e.value::bigint) as mentions')
            ->pluck('mentions', 'competitor_id');

        $totals = (array) ($this->totalsQuery($filter)->first() ?? []);
        $responses = (int) ($totals['responses'] ?? 0);
        $voices = (int) ($totals['brand_mentioned'] ?? 0) + (int) ($totals['competitor_mentions'] ?? 0);

        $rows = [];
        foreach ($competitors as $id => $name) {
            $count = (int) ($mentions[(string) $id] ?? 0);
            $rows[] = [
                'competitor_id' => (int) $id,
                'name' => (string) $name,
                'mentions' => $count,
                'visibility' => new Metric(Formulas::ratio($count, $responses), $responses),
                'share_of_voice' => new Metric(Formulas::ratio($count, $voices), $voices),
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['mentions'], $a['name']] <=> [$a['mentions'], $b['name']]);

        return $rows;
    }

    /**
     * Stability: for each prompt, engine and day (a round), the share of repetitions where the brand is mentioned,
     * as the mean over those cells and the distribution (share rounded to two decimals => number of cells). Only
     * cells with at least two repetitions count; with one repetition per round there is nothing to compare.
     * n is the number of cells.
     *
     * @return array{mean: Metric, distribution: array<string, int>}
     */
    public function stability(MetricsFilter $filter): array
    {
        $rows = $this->dailyQuery($filter)
            ->where('agg_daily.responses', '>=', 2)
            ->toBase()
            ->selectRaw('round(agg_daily.brand_mentioned::numeric / agg_daily.responses, 2) as share, count(*) as cells, sum(agg_daily.brand_mentioned::numeric / agg_daily.responses) as exact')
            ->groupByRaw('round(agg_daily.brand_mentioned::numeric / agg_daily.responses, 2)')
            ->orderBy('share')
            ->get();

        $cells = (int) $rows->sum(fn (object $row) => (int) $row->cells);
        $distribution = [];
        foreach ($rows as $row) {
            $distribution[number_format((float) $row->share, 2, '.', '')] = (int) $row->cells;
        }

        return [
            'mean' => new Metric(Formulas::ratio($rows->sum(fn (object $row) => (float) $row->exact), $cells), $cells),
            'distribution' => $distribution,
        ];
    }

    /**
     * Stability per prompt and engine: the mean of the daily cells (brand mentioned ÷ answers) for rounds with
     * at least two answers. n is the number of those cells. The prompt table shows this beside visibility.
     *
     * @return list<array{prompt_id: int, engine_key: string, mean: Metric}>
     */
    public function stabilityByPromptEngine(MetricsFilter $filter): array
    {
        $rows = $this->dailyQuery($filter)
            ->where('agg_daily.responses', '>=', 2)
            ->toBase()
            ->selectRaw('agg_daily.prompt_id, agg_daily.engine_key, count(*) as cells, sum(agg_daily.brand_mentioned::numeric / agg_daily.responses) as exact')
            ->groupBy('agg_daily.prompt_id', 'agg_daily.engine_key')
            ->orderBy('agg_daily.prompt_id')
            ->orderBy('agg_daily.engine_key')
            ->get();

        return $rows->map(fn (object $row): array => [
            'prompt_id' => (int) $row->prompt_id,
            'engine_key' => (string) $row->engine_key,
            'mean' => new Metric(Formulas::ratio($row->exact, $row->cells), (int) $row->cells),
        ])->all();
    }

    /**
     * Source share: for each domain, the share of answers that cite it, most cited first. The answer count for
     * the share is the answers of the brand in the same markets and engines and the same period. Source figures
     * have no prompt dimension, so topic, intent, funnel and prompt filters are refused.
     *
     * @return list<array{domain: string, citations: int, responses_with_domain: int, share: float|null, n: int}>
     */
    public function sourceShare(MetricsFilter $filter, int $limit = 50): array
    {
        $n = $this->sourceResponses($filter);

        return $this->sourceQuery($filter, 'responses_with_domain')
            ->limit($limit)->get()
            ->map(fn (object $row): array => [
                'domain' => (string) $row->domain,
                'citations' => (int) $row->citations,
                'responses_with_domain' => (int) $row->responses_with_domain,
                'share' => Formulas::ratio($row->responses_with_domain, $n),
                'n' => $n,
            ])->all();
    }

    /**
     * Paginated domains for the REST API, using the same counters and formulas as the dashboard.
     *
     * @return LengthAwarePaginator<int|string, array{domain: string, citations: int, responses_with_domain: int, share: float|null, n: int, gap_responses: int, gap_rate: float|null, gap_n: int}>
     */
    public function sourcesPage(MetricsFilter $filter, int $perPage): LengthAwarePaginator
    {
        $n = $this->sourceResponses($filter);
        $gapN = (int) $this->dailyQuery($filter)->sum('agg_daily.competitor_only_responses');

        return $this->sourceQuery($filter, 'responses_with_domain')->paginate($perPage)->through(fn (object $row) => [
            'domain' => (string) $row->domain,
            'citations' => (int) $row->citations,
            'responses_with_domain' => (int) $row->responses_with_domain,
            'share' => Formulas::ratio($row->responses_with_domain, $n),
            'n' => $n,
            'gap_responses' => (int) $row->gap_responses,
            'gap_rate' => Formulas::ratio($row->gap_responses, $gapN),
            'gap_n' => $gapN,
        ]);
    }

    /**
     * Source gap: domains cited in answers that name competitors but not the brand, most frequent first. The rate
     * is the gap answers of the domain ÷ all such answers of the brand (competitor_only_responses); n is that
     * count. A domain the brand owns is never a gap.
     *
     * @return list<array{domain: string, gap_responses: int, gap_rate: float|null, n: int}>
     */
    public function sourceGap(MetricsFilter $filter, int $limit = 50): array
    {
        $n = (int) ($this->dailyQuery($this->withoutPromptLimits($filter))->sum('agg_daily.competitor_only_responses'));

        return $this->sourceQuery($filter, 'gap_responses')
            ->havingRaw('sum(gap_responses) > 0')
            ->limit($limit)->get()
            ->map(fn (object $row): array => [
                'domain' => (string) $row->domain,
                'gap_responses' => (int) $row->gap_responses,
                'gap_rate' => Formulas::ratio($row->gap_responses, $n),
                'n' => $n,
            ])->all();
    }

    /**
     * AI bot hits (docs/spec.md 9, 10.1): hits per bot in the period, most first. Filtered by brand and period.
     *
     * @return list<array{bot: string, hits: int}>
     */
    public function botHits(MetricsFilter $filter): array
    {
        return BotHitDaily::query()
            ->where('brand_id', $filter->brandId)
            ->whereBetween('date', [$filter->from, $filter->to])
            ->toBase()
            ->selectRaw('bot, sum(hits) as hits')
            ->groupBy('bot')->orderByDesc('hits')->orderBy('bot')
            ->get()
            ->map(fn (object $row): array => ['bot' => (string) $row->bot, 'hits' => (int) $row->hits])->all();
    }

    /**
     * AI traffic (docs/spec.md 9, 10.2): sessions, engaged sessions and conversions per source in the period.
     *
     * @return list<array{source: string, sessions: int, engaged_sessions: int, conversions: float}>
     */
    public function aiTraffic(MetricsFilter $filter): array
    {
        return AiReferralDaily::query()
            ->where('brand_id', $filter->brandId)
            ->whereBetween('date', [$filter->from, $filter->to])
            ->toBase()
            ->selectRaw('source, sum(sessions) as sessions, sum(engaged_sessions) as engaged_sessions, sum(conversions) as conversions')
            ->groupBy('source')->orderByDesc('sessions')->orderBy('source')
            ->get()
            ->map(fn (object $row): array => [
                'source' => (string) $row->source,
                'sessions' => (int) $row->sessions,
                'engaged_sessions' => (int) $row->engaged_sessions,
                'conversions' => (float) $row->conversions,
            ])->all();
    }

    /**
     * Share of voice split between the brand and each active competitor (docs/spec.md 9.1).
     * Mentions come from agg_daily. The denominator is the brand plus those competitors, so the
     * parts sum to the same share of voice as summary().
     *
     * @return list<array{id: string, name: string|null, mentions: int, share: float|null, n: int}>
     */
    public function voiceParts(MetricsFilter $filter): array
    {
        $competitors = Competitor::query()
            ->where('brand_id', $filter->brandId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Scan the filtered daily rows once, rather than once per competitor and counter.
        $query = $this->dailyQuery($filter)->toBase()->selectRaw(
            'coalesce(sum(agg_daily.responses), 0) as responses, coalesce(sum(agg_daily.brand_mentioned), 0) as brand_mentioned',
        );
        $this->selectCompetitorCounters($query, $competitors);

        return $this->voiceFromTotals((array) $query->first(), $competitors);
    }

    /** @param Collection<int, Competitor> $competitors */
    private function selectCompetitorCounters(\Illuminate\Database\Query\Builder $query, Collection $competitors): void
    {
        foreach ($competitors as $index => $competitor) {
            $query->selectRaw(
                "coalesce(sum((case when jsonb_typeof(agg_daily.competitor_mentions) = 'object' then agg_daily.competitor_mentions->>? else null end)::bigint), 0) as competitor_{$index}",
                [(string) $competitor->id],
            );
        }

    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  Collection<int, Competitor>  $competitors
     * @return list<array{id: string, name: string|null, mentions: int, share: float|null, n: int}>
     */
    private function voiceFromTotals(array $totals, Collection $competitors): array
    {
        $responses = (int) $totals['responses'];
        $parts = [[
            'id' => 'brand',
            'name' => null,
            'mentions' => (int) $totals['brand_mentioned'],
        ]];

        foreach ($competitors as $index => $competitor) {
            $parts[] = [
                'id' => (string) $competitor->id,
                'name' => $competitor->name,
                'mentions' => (int) $totals["competitor_{$index}"],
            ];
        }

        $total = array_sum(array_column($parts, 'mentions'));

        return array_map(fn (array $part): array => [
            ...$part,
            'share' => Formulas::ratio($part['mentions'], $total),
            'n' => $responses,
        ], $parts);
    }

    /**
     * Model change markers (docs/spec.md 7.5) in the period, oldest first, optionally for some engines.
     *
     * @param  list<string>  $engineKeys
     * @return Collection<int, ModelChange>
     */
    public function modelChanges(string $from, string $to, array $engineKeys = []): Collection
    {
        $range = new MetricsFilter(0, $from, $to);

        return ModelChange::query()
            ->whereBetween('changed_on', [$range->from, $range->to])
            ->when($engineKeys !== [], fn (Builder $query) => $query->whereIn('engine_key', $engineKeys))
            ->orderBy('changed_on')->orderBy('engine_key')
            ->get();
    }

    /**
     * Own-domain citation per round (ADR 0019): for each prompt and engine and day, the share of answers with
     * a citation of an own domain, oldest day first. A round is the day, as for stability (ADR 0012).
     *
     * @return list<array{prompt_id: int, engine_key: string, date: string, citation: Metric}>
     */
    public function citationRounds(MetricsFilter $filter): array
    {
        $rows = $this->dailyQuery($filter)->toBase()
            ->selectRaw('agg_daily.prompt_id, agg_daily.engine_key, agg_daily.date, sum(agg_daily.responses) as responses, sum(agg_daily.own_cited) as own_cited')
            ->groupBy('agg_daily.prompt_id', 'agg_daily.engine_key', 'agg_daily.date')
            ->orderBy('agg_daily.date')->orderBy('agg_daily.prompt_id')->orderBy('agg_daily.engine_key')
            ->get();

        return $rows->map(fn (object $row): array => [
            'prompt_id' => (int) $row->prompt_id,
            'engine_key' => (string) $row->engine_key,
            'date' => substr((string) $row->date, 0, 10),
            'citation' => new Metric(Formulas::ratio($row->own_cited, $row->responses), (int) $row->responses),
        ])->all();
    }

    /**
     * First-place rate of each active competitor of the brand per prompt: the share of list answers where the
     * competitor stands first. n is the list answers of the prompt (a list answer is defined as in ADR 0012).
     * agg_daily has no competitor positions, so this reads the mentions of the answers in the period. Only
     * answers of runs with status extracted count, and the prompts are limited by the filter's prompt ids.
     *
     * @return array<int, array<int, Metric>> prompt id => competitor id => Metric
     */
    public function competitorFirstPlace(MetricsFilter $filter): array
    {
        [$start, $end] = $this->window($filter);
        $competitorIds = Competitor::query()
            ->where('brand_id', $filter->brandId)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($competitorIds === []) {
            return [];
        }

        $listAnswers = Response::query()
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->where('runs.status', 'extracted')
            ->when($filter->promptIds !== [], fn (Builder $q) => $q->whereIn('runs.prompt_id', $filter->promptIds))
            ->where('responses.collected_at', '>=', $start)->where('responses.collected_at', '<', $end)
            ->whereRaw("(responses.answer_type = 'list' or exists (select 1 from mentions m where m.response_id = responses.id and m.tenant_id = responses.tenant_id and m.list_position is not null and m.entity_type in ('brand', 'competitor')))")
            ->toBase()
            ->selectRaw('runs.prompt_id, count(*) as list_answers')
            ->groupBy('runs.prompt_id')
            ->pluck('list_answers', 'prompt_id');

        $firstPlaces = Mention::query()
            ->join('responses', 'responses.id', '=', 'mentions.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->where('runs.status', 'extracted')
            ->where('mentions.entity_type', 'competitor')
            ->where('mentions.list_position', 1)
            ->whereIn('mentions.entity_id', $competitorIds)
            ->when($filter->promptIds !== [], fn (Builder $q) => $q->whereIn('runs.prompt_id', $filter->promptIds))
            ->where('responses.collected_at', '>=', $start)->where('responses.collected_at', '<', $end)
            ->toBase()
            ->selectRaw('runs.prompt_id, mentions.entity_id as competitor_id, count(distinct mentions.response_id) as first_places')
            ->groupBy('runs.prompt_id', 'mentions.entity_id')
            ->get();

        $first = [];
        foreach ($firstPlaces as $row) {
            $first[(int) $row->prompt_id][(int) $row->competitor_id] = (int) $row->first_places;
        }

        $result = [];
        foreach ($listAnswers as $promptId => $count) {
            foreach ($competitorIds as $competitorId) {
                $result[(int) $promptId][$competitorId] = new Metric(
                    Formulas::ratio($first[(int) $promptId][$competitorId] ?? 0, $count),
                    (int) $count,
                );
            }
        }

        return $result;
    }

    /**
     * Brands the detection could not place (mentions of entity type unknown) in the answers of the filter's
     * markets and period: the share of answers mentioning each, with n = the answers in those markets. Names
     * are compared case-insensitively. Only answers of runs with status extracted count. Largest share first.
     *
     * @return list<array{key: string, name: string, metric: Metric}>
     */
    public function unknownBrands(MetricsFilter $filter): array
    {
        [$start, $end] = $this->window($filter);

        $scope = fn (Builder $query): Builder => $query
            ->where('runs.status', 'extracted')
            ->when($filter->marketIds !== [], fn (Builder $q) => $q->whereIn('prompts.market_id', $filter->marketIds))
            ->where('responses.collected_at', '>=', $start)->where('responses.collected_at', '<', $end);

        $answers = (int) $scope(Response::query()
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id'))
            ->toBase()->count('responses.id');
        if ($answers === 0) {
            return [];
        }

        $rows = $scope(Mention::query()
            ->join('responses', 'responses.id', '=', 'mentions.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id'))
            ->where('mentions.entity_type', 'unknown')
            ->whereNotNull('mentions.surface_name')
            ->toBase()
            ->selectRaw('lower(trim(mentions.surface_name)) as name_key, min(trim(mentions.surface_name)) as name, count(distinct mentions.response_id) as answers')
            ->groupByRaw('lower(trim(mentions.surface_name))')
            ->orderByDesc('answers')->orderBy('name_key')
            ->get();

        return $rows->filter(fn (object $row): bool => (string) $row->name_key !== '')->map(fn (object $row): array => [
            'key' => (string) $row->name_key,
            'name' => (string) $row->name,
            'metric' => new Metric(Formulas::ratio($row->answers, $answers), $answers),
        ])->values()->all();
    }

    /**
     * Reach of a filter for the expected effect of an action (docs/spec.md 11.3): the answers and the answers that
     * name a competitor but not the brand (competitor_only_responses), summed from agg_daily.
     *
     * @return array{responses: int, competitor_only_responses: int}
     */
    public function reach(MetricsFilter $filter): array
    {
        $row = $this->dailyQuery($filter)->toBase()
            ->selectRaw('coalesce(sum(agg_daily.responses), 0) as responses, coalesce(sum(agg_daily.competitor_only_responses), 0) as competitor_only_responses')
            ->first();

        return [
            'responses' => (int) ($row->responses ?? 0),
            'competitor_only_responses' => (int) ($row->competitor_only_responses ?? 0),
        ];
    }

    /**
     * Who and what lies behind the source gap of one domain (docs/spec.md 9 and 11.3): the answers that cite the
     * domain, the gap answers among them (a competitor of the brand named, the brand not, the domain not the
     * brand's own; same rule as DailyAggregator), the competitors named in the gap answers, and the prompts and
     * engines of the gap answers. Read from the extracted answers of the filter's markets and period; topic,
     * intent, funnel and prompt filters are refused like the other source figures.
     *
     * @return array{responses_with_domain: int, gap_responses: int, competitors: list<array{competitor_id: int, name: string, answers: int}>, prompts: array<int, int>, engines: list<string>}
     */
    public function sourceGapDetail(MetricsFilter $filter, string $domain): array
    {
        $this->refuseSourcePromptLimits($filter);
        $citing = $this->extractedAnswers($filter)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('citations')
                ->whereColumn('citations.response_id', 'responses.id')->where('citations.domain', $domain));

        $gap = (clone $citing)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('citations')
                ->whereColumn('citations.response_id', 'responses.id')->where('citations.domain', $domain)->where('citations.ownership', 'own'))
            ->whereNotExists(fn ($q) => $this->brandMention($q, $filter->brandId))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('mentions')
                ->join('competitors', 'competitors.id', '=', 'mentions.entity_id')
                ->whereColumn('mentions.response_id', 'responses.id')
                ->where('mentions.entity_type', 'competitor')
                ->where('competitors.brand_id', $filter->brandId));

        $gapIds = (clone $gap)->select('responses.id');
        $competitors = Mention::query()
            ->join('competitors', 'competitors.id', '=', 'mentions.entity_id')
            ->where('mentions.entity_type', 'competitor')
            ->where('competitors.brand_id', $filter->brandId)
            ->whereIn('mentions.response_id', $gapIds)
            ->toBase()
            ->selectRaw('competitors.id as competitor_id, competitors.name, count(distinct mentions.response_id) as answers')
            ->groupBy('competitors.id', 'competitors.name')
            ->orderByDesc('answers')->orderBy('competitors.name')
            ->get()
            ->map(fn (object $row): array => ['competitor_id' => (int) $row->competitor_id, 'name' => (string) $row->name, 'answers' => (int) $row->answers])
            ->all();

        return [
            'responses_with_domain' => (int) (clone $citing)->count('responses.id'),
            'gap_responses' => (int) (clone $gap)->count('responses.id'),
            'competitors' => array_values($competitors),
            'prompts' => $this->answersPerPrompt(clone $gap),
            'engines' => $this->enginesOf(clone $gap),
        ];
    }

    /**
     * The answers behind one unknown brand of {@see unknownBrands()} (key = lower-case trimmed name): how many
     * answers name it, how many of those do not name the brand, and their prompts and engines.
     *
     * @return array{answers: int, without_brand: int, prompts: array<int, int>, engines: list<string>}
     */
    public function unknownBrandDetail(MetricsFilter $filter, string $key): array
    {
        $naming = $this->extractedAnswers($filter)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('mentions')
                ->whereColumn('mentions.response_id', 'responses.id')
                ->where('mentions.entity_type', 'unknown')
                ->whereRaw('lower(trim(mentions.surface_name)) = ?', [$key]));

        return [
            'answers' => (int) (clone $naming)->count('responses.id'),
            'without_brand' => (int) (clone $naming)->whereNotExists(fn ($q) => $this->brandMention($q, $filter->brandId))->count('responses.id'),
            'prompts' => $this->answersPerPrompt(clone $naming),
            'engines' => $this->enginesOf(clone $naming),
        ];
    }

    /**
     * Extracted answers of the filter's brand, markets, engines and tenant-local period (only runs with status
     * extracted count, ADR 0012).
     *
     * @return Builder<Response>
     */
    private function extractedAnswers(MetricsFilter $filter): Builder
    {
        [$start, $end] = $this->window($filter);

        return Response::query()
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('runs.status', 'extracted')
            ->where('markets.brand_id', $filter->brandId)
            ->when($filter->marketIds !== [], fn (Builder $q) => $q->whereIn('prompts.market_id', $filter->marketIds))
            ->when($filter->engineKeys !== [], fn (Builder $q) => $q->whereIn('responses.engine_key', $filter->engineKeys))
            ->where('responses.collected_at', '>=', $start)->where('responses.collected_at', '<', $end);
    }

    private function brandMention(\Illuminate\Database\Query\Builder $query, int $brandId): \Illuminate\Database\Query\Builder
    {
        return $query->selectRaw('1')->from('mentions')
            ->whereColumn('mentions.response_id', 'responses.id')
            ->where('mentions.entity_type', 'brand')
            ->where('mentions.entity_id', $brandId);
    }

    /**
     * @param  Builder<Response>  $answers
     * @return array<int, int> prompt id => answers, most answers first
     */
    private function answersPerPrompt(Builder $answers): array
    {
        $result = [];
        foreach ($answers->toBase()->selectRaw('runs.prompt_id, count(distinct responses.id) as answers')
            ->groupBy('runs.prompt_id')->orderByDesc('answers')->orderBy('runs.prompt_id')->get() as $row) {
            $result[(int) $row->prompt_id] = (int) $row->answers;
        }

        return $result;
    }

    /**
     * @param  Builder<Response>  $answers
     * @return list<string>
     */
    private function enginesOf(Builder $answers): array
    {
        return $answers->toBase()->distinct()->orderBy('responses.engine_key')->pluck('responses.engine_key')
            ->map(fn ($key): string => (string) $key)->values()->all();
    }

    /**
     * The model id an engine answered with most often on each tenant-local day (answers with a model id, any
     * run status). A tie goes to the model of the previous day when it is among the tied, otherwise to the
     * smallest id, so a tie does not flip back and forth. Days without answers are left out. Providers alternate
     * between versions during a day, so alerts compare these, not the markers of {@see modelChanges()}.
     *
     * @return array<string, array<string, string>> engine key => day (Y-m-d) => model id, oldest day first
     */
    public function dominantModels(string $from, string $to): array
    {
        $range = new MetricsFilter(0, $from, $to);
        [$start, $end] = $this->window($range);
        $timezone = $this->timezone()->getName();

        $rows = Response::query()
            ->whereNotNull('model_id_reported')
            ->where('collected_at', '>=', $start)->where('collected_at', '<', $end)
            ->toBase()
            ->selectRaw('engine_key, (collected_at at time zone ?)::date as day, model_id_reported as model, count(*) as answers', [$timezone])
            ->groupByRaw('1, 2, 3')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->engine_key][substr((string) $row->day, 0, 10)][(string) $row->model] = (int) $row->answers;
        }

        $result = [];
        foreach ($counts as $engine => $days) {
            ksort($days);
            $previous = null;
            foreach ($days as $day => $models) {
                $top = max($models);
                $tied = array_keys(array_filter($models, fn (int $count): bool => $count === $top));
                sort($tied, SORT_STRING);
                $previous = $previous !== null && in_array($previous, $tied, true) ? $previous : $tied[0];
                $result[$engine][$day] = $previous;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * The UTC range [start, end) of the filter's tenant-local days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(MetricsFilter $filter): array
    {
        $timezone = $this->timezone();

        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $filter->from, $timezone)->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $filter->to, $timezone)->addDay()->startOfDay()->utc(),
        ];
    }

    private function timezone(): DateTimeZone
    {
        $name = Team::query()->whereKey(current_tenant_id())->value('timezone');

        try {
            return new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * agg_daily rows for the filter.
     *
     * @return Builder<AggDaily>
     */
    private function dailyQuery(MetricsFilter $filter, bool $joinPrompts = false): Builder
    {
        $query = AggDaily::query()
            ->where('agg_daily.brand_id', $filter->brandId)
            ->whereBetween('agg_daily.date', [$filter->from, $filter->to])
            ->when($filter->marketIds !== [], fn (Builder $q) => $q->whereIn('agg_daily.market_id', $filter->marketIds))
            ->when($filter->engineKeys !== [], fn (Builder $q) => $q->whereIn('agg_daily.engine_key', $filter->engineKeys))
            ->when($filter->promptIds !== [], fn (Builder $q) => $q->whereIn('agg_daily.prompt_id', $filter->promptIds));

        if ($joinPrompts) {
            $query->join('prompts', 'prompts.id', '=', 'agg_daily.prompt_id');
        }

        if ($filter->topicIds !== [] || $filter->intents !== [] || $filter->funnelStages !== []) {
            $query->whereIn('agg_daily.prompt_id', Prompt::query()->select('prompts.id')
                ->when($filter->topicIds !== [], fn (Builder $q) => $q->whereIn('prompts.topic_id', $filter->topicIds))
                ->when($filter->intents !== [], fn (Builder $q) => $q->whereIn('prompts.intent', $filter->intents))
                ->when($filter->funnelStages !== [], fn (Builder $q) => $q->whereIn('prompts.funnel_stage', $filter->funnelStages)));
        }

        return $query;
    }

    /**
     * The summed counters, optionally grouped by an expression, as a query on the base builder.
     */
    private function totalsQuery(MetricsFilter $filter, ?string $groupBy = null, string $groupAs = 'grouped', bool $joinPrompts = false): \Illuminate\Database\Query\Builder
    {
        $competitorIds = Competitor::query()
            ->where('brand_id', $filter->brandId)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        // The mentions of the brand's active competitors: competitor_mentions is {competitor_id: answers}.
        $competitors = $competitorIds === []
            ? '0'
            : "coalesce(sum((select coalesce(sum(e.value::bigint), 0) from jsonb_each_text(case when jsonb_typeof(agg_daily.competitor_mentions) = 'object' then agg_daily.competitor_mentions else '{}'::jsonb end) e where e.key = any (string_to_array(?, ',')))), 0)";

        $columns = $this->counterColumns().", {$competitors} as competitor_mentions";
        $bindings = $competitorIds === [] ? [] : [implode(',', $competitorIds)];

        $query = $this->dailyQuery($filter, $joinPrompts)->toBase();

        if ($groupBy !== null) {
            $query->selectRaw("{$groupBy} as {$groupAs}, {$columns}", $bindings)->groupByRaw($groupBy);
        } else {
            $query->selectRaw($columns, $bindings);
        }

        return $query;
    }

    private function counterColumns(): string
    {
        return implode(', ', array_map(fn (string $counter): string => "coalesce(sum(agg_daily.{$counter}), 0) as {$counter}", self::COUNTERS));
    }

    /**
     * agg_source_daily rows grouped by domain, ordered by a counter.
     */
    private function sourceQuery(MetricsFilter $filter, string $orderBy): \Illuminate\Database\Query\Builder
    {
        $this->refuseSourcePromptLimits($filter);

        return AggSourceDaily::query()
            ->where('brand_id', $filter->brandId)
            ->whereBetween('date', [$filter->from, $filter->to])
            ->when($filter->marketIds !== [], fn (Builder $q) => $q->whereIn('market_id', $filter->marketIds))
            ->when($filter->engineKeys !== [], fn (Builder $q) => $q->whereIn('engine_key', $filter->engineKeys))
            ->toBase()
            ->selectRaw('domain, sum(citations) as citations, sum(responses_with_domain) as responses_with_domain, sum(gap_responses) as gap_responses')
            ->groupBy('domain')
            ->orderByDesc($orderBy)->orderBy('domain');
    }

    private function sourceResponses(MetricsFilter $filter): int
    {
        $this->refuseSourcePromptLimits($filter);

        return (int) $this->dailyQuery($filter)->sum('agg_daily.responses');
    }

    private function refuseSourcePromptLimits(MetricsFilter $filter): void
    {
        if ($filter->limitsPrompts()) {
            throw new InvalidArgumentException('Source figures cannot be limited by topic, intent, funnel stage or prompt.');
        }
    }

    private function withoutPromptLimits(MetricsFilter $filter): MetricsFilter
    {
        $this->refuseSourcePromptLimits($filter);

        return $filter;
    }
}
