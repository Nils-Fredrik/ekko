<?php

namespace App\Dashboard;

use App\Detection\DomainOwnership;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Metrics\Formulas;
use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Action;
use App\Models\Brand;
use App\Models\BrandDomain;
use App\Models\Competitor;
use App\Models\CompetitorDomain;
use App\Models\Response;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The data of the sources page (docs/ui-forbedring.md 7.5, U08): the ranked domains with their ownership, the
 * source gaps with the action generated for each (T43), the citations by source type, and the answers that cite
 * one domain. The figures come from MetricsService; this class only joins them with the brand's configuration.
 */
final class SourcePage
{
    /** Domains in the ranked list. */
    public const RANKED = 20;

    /** Domains in the source gap. */
    public const GAPS = 10;

    /** Answers shown for one domain. */
    public const ANSWERS = 20;

    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * The deferred chart data. A domain's ownership follows the brand's and its competitors' domains as they are
     * configured now (DomainOwnership), so a competitor's domain has that competitor's color on every page.
     *
     * @return array{sources: list<array<string, mixed>>, competitors: list<array{id: int, name: string, color_index: int}>, types: array{complete: bool, items: list<array{key: string, value: int}>}, gaps: list<array<string, mixed>>}
     */
    public function chartData(Brand $brand, MetricsFilter $filter): array
    {
        $ownership = $this->ownership($brand);
        $distribution = $this->metrics->citationDistribution($filter);
        $typesByDomain = [];
        $types = [];
        foreach ($distribution['rows'] ?? [] as $row) {
            $typesByDomain[$row['domain']][$row['source_type']] = true;
            $types[$row['source_type']] = ($types[$row['source_type']] ?? 0) + $row['citations'];
        }
        arsort($types);

        $sources = array_map(function (array $row) use ($ownership, $typesByDomain): array {
            $owner = $ownership->classify($row['domain']);

            return [
                'domain' => $row['domain'],
                'citations' => $row['citations'],
                'responses_with_domain' => $row['responses_with_domain'],
                'share' => $row['share'],
                'n' => $row['n'],
                'low_sample' => $row['n'] < Metric::LOW_SAMPLE,
                'ownership' => $owner['ownership'],
                'competitor_id' => $owner['competitor_id'],
                'types' => array_keys($typesByDomain[$row['domain']] ?? []),
            ];
        }, $this->metrics->sourceShare($filter, self::RANKED));

        return [
            'sources' => $sources,
            'competitors' => Competitor::query()
                ->where('brand_id', $brand->id)->where('is_active', true)
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])
                ->values()
                ->map(fn (Competitor $competitor, int $index): array => [
                    'id' => (int) $competitor->id, 'name' => (string) $competitor->name, 'color_index' => $index,
                ])->all(),
            'types' => [
                'complete' => $distribution['complete'],
                'items' => array_map(fn (string $type, int $citations): array => ['key' => $type, 'value' => $citations], array_keys($types), array_values($types)),
            ],
            'gaps' => $this->gaps($brand, $filter),
        ];
    }

    /**
     * The answers of the filter that cite the domain, newest first, with the cited URLs of that domain.
     *
     * @return array{domain: string, total: int, answers: list<array<string, mixed>>}
     */
    public function citing(Brand $brand, MetricsFilter $filter, string $domain): array
    {
        $query = $this->metrics->citingAnswers($filter, $domain);
        $total = (clone $query)->count('responses.id');
        $answers = $query->limit(self::ANSWERS)
            ->with([
                'run.prompt.market',
                'citations' => fn ($citations) => $citations->where('domain', $domain)->orderBy('rank_in_answer')->orderBy('id'),
                'mentions' => fn ($mentions) => $mentions->where('entity_type', 'brand')->where('entity_id', $brand->id),
            ])
            ->get();
        $engines = DashboardLabels::engines($answers->pluck('engine_key')->unique()->values()->all());

        return [
            'domain' => $domain,
            'total' => $total,
            'answers' => $answers->map(function (Response $response) use ($brand, $engines): array {
                $prompt = $response->run?->prompt;
                $market = $prompt?->market;
                $collected = $response->collected_at;

                return [
                    'id' => $response->id,
                    'collected_at' => $collected instanceof CarbonInterface ? $collected->toIso8601String() : '',
                    'engine' => $engines[$response->engine_key] ?? DashboardLabels::engine((string) $response->engine_key),
                    'prompt' => $prompt === null ? null : [
                        'text' => $prompt->text,
                        'href' => route('brands.prompts.show', [$brand, $prompt]),
                    ],
                    'market' => $market === null ? '' : ($market->location_label ?: strtoupper($market->country)),
                    'brand_mentioned' => $response->mentions->isNotEmpty(),
                    'urls' => $response->citations->map(fn ($citation): ?string => self::httpUrl($citation->url))
                        ->filter()->unique()->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The largest source gaps, each compared with how often the domain is cited when the brand is named, and the
     * action generated for it (an open one first, never an expired one).
     *
     * @return list<array<string, mixed>>
     */
    private function gaps(Brand $brand, MetricsFilter $filter): array
    {
        $gaps = $this->metrics->sourceGap($filter, self::GAPS);
        $domains = array_column($gaps, 'domain');
        $comparison = $this->metrics->sourceGapComparison($filter, $domains);
        $actions = $this->actions($brand, $filter, $domains);

        return array_map(function (array $gap) use ($comparison, $actions): array {
            $counts = $comparison['rows'][$gap['domain']] ?? ['own' => 0, 'competitors' => 0];

            return [
                'domain' => $gap['domain'],
                'gap_responses' => $gap['gap_responses'],
                'gap_rate' => $gap['gap_rate'],
                'n' => $gap['n'],
                'low_sample' => $gap['n'] < Metric::LOW_SAMPLE,
                'own' => self::side($counts['own'], $comparison['own_n']),
                'competitors' => self::side($counts['competitors'], $comparison['competitor_n']),
                'action' => $actions[$gap['domain']] ?? null,
            ];
        }, $gaps);
    }

    /**
     * The source_gap action of each domain. With a market filter only that market's actions count.
     *
     * @param  list<string>  $domains
     * @return array<string, array{id: int, status: string, href: string}>
     */
    private function actions(Brand $brand, MetricsFilter $filter, array $domains): array
    {
        if ($domains === []) {
            return [];
        }
        $open = array_map(fn (ActionStatus $status): string => $status->value, ActionStatus::open());
        $found = Action::query()
            ->where('brand_id', $brand->id)
            ->where('type', ActionType::SourceGap->value)
            ->where('status', '!=', ActionStatus::Expired->value)
            ->whereIn(DB::raw("evidence->>'domain'"), $domains)
            ->when($filter->marketIds !== [], fn ($q) => $q->whereIn('market_id', $filter->marketIds))
            ->orderByRaw('CASE WHEN status IN ('.implode(', ', array_fill(0, count($open), '?')).') THEN 0 ELSE 1 END', $open)
            ->orderByDesc('impact_level')->orderByDesc('impact_score')->orderBy('id')
            ->get(['id', 'brand_id', 'status', 'evidence']);

        $actions = [];
        foreach ($found as $action) {
            $domain = (string) ($action->evidence['domain'] ?? '');
            $actions[$domain] ??= [
                'id' => (int) $action->id,
                'status' => $action->status->value,
                'href' => route('brands.actions.show', [$brand, $action]),
            ];
        }

        return $actions;
    }

    private function ownership(Brand $brand): DomainOwnership
    {
        return new DomainOwnership(
            BrandDomain::query()->where('brand_id', $brand->id)->get()
                ->map(fn (BrandDomain $row): array => ['domain' => (string) $row->domain, 'include_subdomains' => (bool) $row->include_subdomains]),
            CompetitorDomain::query()
                ->whereIn('competitor_id', Competitor::query()->where('brand_id', $brand->id)->select('id'))
                ->get()
                ->map(fn (CompetitorDomain $row): array => ['domain' => (string) $row->domain, 'competitor_id' => (int) $row->competitor_id]),
        );
    }

    /** @return array{value: float|null, n: int, low_sample: bool} */
    private static function side(int $count, int $n): array
    {
        return ['value' => Formulas::ratio($count, $n), 'n' => $n, 'low_sample' => $n < Metric::LOW_SAMPLE];
    }

    private static function httpUrl(mixed $url): ?string
    {
        return is_string($url) && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) ? $url : null;
    }
}
