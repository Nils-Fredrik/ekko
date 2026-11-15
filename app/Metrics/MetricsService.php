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
    /** Grain names for {@see series()}. */
    public const GRAINS = ['day', 'week', 'month'];

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
        $responses = (int) $this->dailyQuery($filter)->sum('agg_daily.responses');
        $brandMentions = (int) $this->dailyQuery($filter)->sum('agg_daily.brand_mentioned');
        $parts = [[
            'id' => 'brand',
            'name' => null,
            'mentions' => $brandMentions,
        ]];

        $competitors = Competitor::query()
            ->where('brand_id', $filter->brandId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($competitors as $competitor) {
            $parts[] = [
                'id' => (string) $competitor->id,
                'name' => $competitor->name,
                'mentions' => $this->competitorMentions($filter, $competitor->id),
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

    private function competitorMentions(MetricsFilter $filter, int $competitorId): int
    {
        return (int) $this->dailyQuery($filter)->toBase()->selectRaw(
            "coalesce(sum((case when jsonb_typeof(agg_daily.competitor_mentions) = 'object' then agg_daily.competitor_mentions->>? else null end)::bigint), 0) as mentions",
            [(string) $competitorId],
        )->value('mentions');
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

        $columns = implode(', ', [
            'coalesce(sum(agg_daily.responses), 0) as responses',
            'coalesce(sum(agg_daily.brand_mentioned), 0) as brand_mentioned',
            'coalesce(sum(agg_daily.brand_recommended), 0) as brand_recommended',
            'coalesce(sum(agg_daily.sum_position), 0) as sum_position',
            'coalesce(sum(agg_daily.count_position), 0) as count_position',
            'coalesce(sum(agg_daily.list_responses), 0) as list_responses',
            'coalesce(sum(agg_daily.first_place), 0) as first_place',
            'coalesce(sum(agg_daily.sum_sentiment), 0) as sum_sentiment',
            'coalesce(sum(agg_daily.count_sentiment), 0) as count_sentiment',
            'coalesce(sum(agg_daily.own_cited), 0) as own_cited',
            "{$competitors} as competitor_mentions",
        ]);
        $bindings = $competitorIds === [] ? [] : [implode(',', $competitorIds)];

        $query = $this->dailyQuery($filter, $joinPrompts)->toBase();

        if ($groupBy !== null) {
            $query->selectRaw("{$groupBy} as {$groupAs}, {$columns}", $bindings)->groupByRaw($groupBy);
        } else {
            $query->selectRaw($columns, $bindings);
        }

        return $query;
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
