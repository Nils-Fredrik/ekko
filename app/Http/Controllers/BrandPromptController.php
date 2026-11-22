<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Dashboard\DashboardPeriod;
use App\Dashboard\GraphCache;
use App\Engines\RawResponseStore;
use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\Prompt;
use App\Models\Response;
use App\Prompts\AnswerSegments;
use App\Prompts\PersonalData;
use App\Prompts\PromptCsv;
use App\Prompts\PromptLibrary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Prompt table, one prompt's answers, and CSV import and export (docs/spec.md 14.2, T27).
 * Figures come from MetricsService. Mentions in the answer text are highlighted.
 */
class BrandPromptController extends Controller
{
    public function index(Request $request, Brand $brand, MetricsService $metrics): InertiaResponse
    {
        BrandPage::authorizeView($brand);
        $brand->load(['markets', 'topics']);
        $query = AnalysisFilters::query($brand, $request->query(), 'brands.prompts.index');
        $period = DashboardPeriod::fromInput($query, $brand->id);
        $selection = AnalysisFilters::selection($query);
        $marketIds = $selection['marketIds'];
        $promptMarketIds = $marketIds === [] ? $brand->markets->pluck('id')->all() : $marketIds;
        $filter = $period->filter($brand->id, ...$selection);
        $dotsFilter = new MetricsFilter($brand->id, CarbonImmutable::parse($period->to)->subDays(13), $period->to, ...$selection);
        $byPrompt = $metrics->breakdown($filter, 'prompt');
        $stability = [];
        foreach ($metrics->stabilityByPromptEngine($filter) as $cell) {
            $stability[$cell['prompt_id']][] = $cell;
        }
        $engineKeys = [];
        foreach ($stability as $cells) {
            foreach ($cells as $cell) {
                $engineKeys[] = $cell['engine_key'];
            }
        }
        $engineLabels = DashboardLabels::engines(array_values(array_unique($engineKeys)));
        $summaries = [];
        foreach ($byPrompt as $promptId => $row) {
            $summaries[(int) $promptId] = $row;
        }

        $prompts = Prompt::query()
            ->whereIn('market_id', $promptMarketIds)
            ->when($filter->topicIds !== [], fn ($q) => $q->whereIn('topic_id', $filter->topicIds))
            ->when($filter->intents !== [], fn ($q) => $q->whereIn('intent', $filter->intents))
            ->with(['market', 'topic'])
            ->orderBy('text')
            ->get()
            ->map(function (Prompt $prompt) use ($summaries, $stability, $engineLabels): array {
                $summary = $summaries[$prompt->id] ?? null;
                $visibility = $summary === null ? new Metric(null, 0) : $summary->visibility;
                $position = $summary === null ? new Metric(null, 0) : $summary->averagePosition;

                return [
                    'id' => $prompt->id,
                    'text' => $prompt->text,
                    'market' => $prompt->market === null ? '' : ($prompt->market->location_label ?: strtoupper($prompt->market->country)),
                    'topic' => $prompt->topic_id === null ? '' : $prompt->topic->name,
                    'intent' => $prompt->intent,
                    'intent_label' => DashboardLabels::intent($prompt->intent),
                    'funnel' => DashboardLabels::funnel($prompt->funnel_stage),
                    'priority' => $prompt->priority,
                    'priority_label' => DashboardLabels::priority($prompt->priority),
                    'is_active' => $prompt->is_active,
                    'visibility' => $visibility->toArray(),
                    'position' => $position->toArray(),
                    'stability' => array_map(fn (array $cell): array => [
                        'engine' => $engineLabels[$cell['engine_key']] ?? DashboardLabels::engine($cell['engine_key']),
                        'metric' => $cell['mean']->toArray(),
                    ], $stability[$prompt->id] ?? []),
                ];
            })->all();

        return Inertia::render('Brands/Prompts', [
            'brand' => BrandPage::payload($brand),
            'period' => $period->toArray(),
            'analysisFilters' => AnalysisFilters::payload($brand, 'brands.prompts.index', $query, $request->query()),
            'filters' => $query,
            'markets' => BrandPage::markets($brand),
            'prompts' => $prompts,
            'chartData' => Inertia::defer(fn (): array => app(GraphCache::class)->remember(
                'brand.prompts.u06', $filter, $period->timezone, function () use ($brand, $metrics, $filter, $dotsFilter, $period, $selection): array {
                    $sparkFrom = CarbonImmutable::parse($period->to)->subDays(27)->toDateString();
                    $sparkFilter = new MetricsFilter($filter->brandId, $sparkFrom, $period->to, ...$selection);
                    $heatmap = $metrics->visibilityByPromptEngine($filter);
                    $labels = DashboardLabels::engines(array_values(array_unique(array_column($heatmap, 'engine_key'))));

                    return [
                        'topics' => AnalysisFilters::topicBars($brand, $metrics, $filter),
                        'heatmap' => $heatmap,
                        'engines' => array_map(
                            fn (string $key): array => ['key' => $key, 'name' => $labels[$key] ?? DashboardLabels::engine($key)],
                            array_keys($labels),
                        ),
                        'sparklines' => $metrics->visibilityByPromptSeries($sparkFilter),
                        'sparkline' => ['from' => $sparkFrom, 'to' => $period->to],
                        'stability' => $metrics->stabilityDots($dotsFilter),
                    ];
                },
            )),
        ]);
    }

    public function update(Request $request, Brand $brand, Prompt $prompt): RedirectResponse
    {
        Gate::authorize('update', $prompt);
        $this->promptOf($brand, $prompt);

        $data = $request->validate(PromptLibrary::rules(false));
        app(PromptLibrary::class)->update($prompt, $data);

        return back()->with('personal_data', isset($data['text']) && PersonalData::in($data['text']));
    }

    public function import(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('create', Prompt::class);
        $brand->load('markets');

        return back()->with('prompt_import', PromptCsv::import($brand, PromptLibraryController::csv($request)));
    }

    public function export(Request $request, Brand $brand): HttpResponse
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');

        return response(PromptCsv::export($brand, $request->boolean('include_archived')), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prompts-'.$brand->id.'.csv"',
        ]);
    }

    public function show(Request $request, Brand $brand, Prompt $prompt): InertiaResponse
    {
        BrandPage::authorizeView($brand);
        $prompt = $this->promptOf($brand, $prompt);
        $viewQuery = AnalysisFilters::query($brand, $request->query(), 'brands.prompts.show');
        $period = DashboardPeriod::fromInput($viewQuery, $brand->id);
        $filter = new MetricsFilter($brand->id, $period->from, $period->to, engineKeys: isset($viewQuery['engine']) ? [(string) $viewQuery['engine']] : [], promptIds: [$prompt->id]);
        $from = CarbonImmutable::parse($period->from.' 00:00:00', $period->timezone)->utc();
        $to = CarbonImmutable::parse($period->to.' 23:59:59', $period->timezone)->utc();

        $query = Response::query()
            ->whereHas('run', fn ($run) => $run->where('prompt_id', $prompt->id))
            ->whereBetween('collected_at', [$from, $to])
            ->when($filter->engineKeys !== [], fn ($q) => $q->whereIn('engine_key', $filter->engineKeys));
        $total = (clone $query)->count();
        $presence = (clone $query)
            ->select(['responses.id', 'responses.engine_key', 'responses.collected_at'])
            ->withExists([
                'mentions as brand_mentioned' => fn ($mentions) => $mentions
                    ->where('entity_type', 'brand')
                    ->where('entity_id', $brand->id),
            ])
            ->orderByDesc('collected_at')
            ->get();
        $labels = DashboardLabels::engines($presence->pluck('engine_key')->unique()->values()->all());
        $groups = [];
        foreach ($presence as $row) {
            $key = (string) $row->engine_key;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'engine' => $labels[$key] ?? DashboardLabels::engine($key),
                    'answers' => [],
                    'days' => [],
                ];
            }
            $collected = $row->collected_at;
            $date = $collected instanceof CarbonInterface ? $collected->copy()->timezone($period->timezone)->toDateString() : '';
            if ($date === '') {
                continue;
            }
            if (! isset($groups[$key]['days'][$date])) {
                $groups[$key]['days'][$date] = ['date' => $date, 'mentioned' => false, 'n' => 0];
            }
            $groups[$key]['days'][$date]['n']++;
            $groups[$key]['days'][$date]['mentioned'] = $groups[$key]['days'][$date]['mentioned'] || (bool) $row->getAttribute('brand_mentioned');
        }
        foreach ($groups as $key => $group) {
            $groups[$key]['days'] = array_values($group['days']);
        }

        $responses = (clone $query)
            ->with([
                'mentions' => fn ($mentions) => $mentions->orderBy('first_char_offset')->orderBy('id'),
                'citations',
            ])
            ->orderByDesc('collected_at')
            ->limit(100)
            ->get();

        $caseFold = $this->caseFold($brand);
        foreach ($responses as $response) {
            $key = $response->engine_key;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'engine' => $labels[$key] ?? DashboardLabels::engine($key),
                    'answers' => [],
                    'days' => [],
                ];
            }
            $collected = $response->collected_at;
            $groups[$key]['key'] = $key;
            $groups[$key]['answers'][] = [
                'id' => $response->id,
                'collected_at' => $collected instanceof CarbonInterface ? $collected->toIso8601String() : '',
                'date' => $collected instanceof CarbonInterface ? $collected->copy()->timezone($period->timezone)->toDateString() : '',
                'mentioned' => $response->mentions->contains(
                    fn ($mention): bool => $mention->entity_type === 'brand' && (int) $mention->entity_id === (int) $brand->id,
                ),
                'segments' => AnswerSegments::from((string) $response->answer_text, $response->mentions, $caseFold),
                'citations' => $response->citations->map(fn ($citation): array => [
                    'domain' => (string) $citation->domain,
                    'url' => self::httpUrl($citation->url),
                    'ownership' => DashboardLabels::ownership((string) $citation->ownership),
                ])->all(),
                'raw' => route('brands.responses.raw', [$brand, $response]),
            ];
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['answers'] = $this->withMentionChange($group['answers']);
        }

        return Inertia::render('Brands/PromptShow', [
            'brand' => BrandPage::payload($brand),
            'prompt' => [
                'id' => $prompt->id,
                'text' => $prompt->text,
                'market' => $prompt->market === null ? '' : ($prompt->market->location_label ?: strtoupper($prompt->market->country)),
                'intent' => DashboardLabels::intent($prompt->intent),
                'funnel' => DashboardLabels::funnel($prompt->funnel_stage),
                'priority' => DashboardLabels::priority($prompt->priority),
                'is_active' => $prompt->is_active,
            ],
            'period' => $period->toArray(),
            'analysisFilters' => AnalysisFilters::payload($brand, 'brands.prompts.show', $viewQuery, $request->query()),
            'engines' => array_values($groups),
            'truncated' => $total > 100,
            'shown' => $responses->count(),
        ]);
    }

    /**
     * Oldest answer first, so each change is against the previous answer of the same engine.
     *
     * @param  list<array<string, mixed>>  $answers
     * @return list<array<string, mixed>>
     */
    private function withMentionChange(array $answers): array
    {
        $chronological = array_reverse($answers);
        $previous = null;
        foreach ($chronological as $index => $answer) {
            $mentioned = (bool) $answer['mentioned'];
            $chronological[$index]['change'] = $previous === null
                ? null
                : ($mentioned === $previous ? 'same' : ($mentioned ? 'gained' : 'lost'));
            $previous = $mentioned;
        }

        return array_reverse($chronological);
    }

    /**
     * Later copies of a mention keep the stored spelling, unless that surface is a case_insensitive alias.
     *
     * @return array<string, true>
     */
    private function caseFold(Brand $brand): array
    {
        $brand->loadMissing('aliases');
        $keys = [];

        foreach ($brand->aliases as $alias) {
            if ($alias->match_mode !== 'case_insensitive') {
                continue;
            }
            $key = AnswerSegments::caseFoldKey('brand', $brand->id, (string) $alias->alias);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        foreach ($brand->competitors()->withTrashed()->with('aliases')->get() as $competitor) {
            foreach ($competitor->aliases as $alias) {
                if ($alias->match_mode !== 'case_insensitive') {
                    continue;
                }
                $key = AnswerSegments::caseFoldKey('competitor', $competitor->id, (string) $alias->alias);
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    public function raw(Brand $brand, Response $response): HttpResponse
    {
        Gate::authorize('view', $response);
        $owns = $response->run()
            ->whereHas('prompt.market', fn ($market) => $market->where('brand_id', $brand->id))
            ->exists();
        abort_unless($owns, 404);

        $stored = self::storedRaw($response);
        $body = $stored ?? (string) $response->answer_text;
        $json = $stored !== null;

        return response($body, 200, [
            'Content-Type' => $json ? 'application/json' : 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="response-'.$response->id.($json ? '.json' : '.txt').'"',
        ]);
    }

    /**
     * Provider JSON only when the URI is this tenant's object in the configured raw bucket (docs/spec.md 6).
     */
    private static function storedRaw(Response $response): ?string
    {
        $uri = $response->raw_uri;
        $tenantId = $response->getAttribute('tenant_id');
        if (! is_string($uri) || $uri === '' || (! is_int($tenantId) && ! is_string($tenantId))) {
            return null;
        }
        $prefix = 'gs://'.config()->string('engines.raw_bucket').'/'.$tenantId.'/';
        if (! str_starts_with($uri, $prefix)) {
            return null;
        }

        try {
            return app(RawResponseStore::class)->read($uri);
        } catch (\Throwable) {
            return null;
        }
    }

    private function promptOf(Brand $brand, Prompt $prompt): Prompt
    {
        $prompt->loadMissing('market');
        abort_unless($prompt->market !== null && (int) $prompt->market->brand_id === (int) $brand->id, 404);

        return $prompt;
    }

    private static function httpUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? $url : null;
    }
}
