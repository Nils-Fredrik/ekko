<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Engines\RawResponseStore;
use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Team;
use App\Prompts\AnswerSegments;
use App\Prompts\PersonalData;
use App\Prompts\PromptCsv;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $period = BrandPage::period($request);
        $marketIds = BrandPage::marketIds($brand, $request);
        $promptMarketIds = $marketIds === [] ? $brand->markets->pluck('id')->all() : $marketIds;
        $filter = new MetricsFilter($brand->id, $period->from, $period->to, marketIds: $marketIds);
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
            'period' => ['days' => $period->days],
            'filters' => ['market' => $marketIds[0] ?? null],
            'markets' => BrandPage::markets($brand),
            'prompts' => $prompts,
            'intents' => array_map(fn (string $intent): array => ['value' => $intent, 'label' => DashboardLabels::intent($intent)], Prompt::INTENTS),
            'priorities' => array_map(fn (string $priority): array => ['value' => $priority, 'label' => DashboardLabels::priority($priority)], Prompt::PRIORITIES),
            'canManage' => Gate::allows('create', Prompt::class),
            'importResult' => session('prompt_import'),
            'personalData' => (bool) session('personal_data'),
        ]);
    }

    public function update(Request $request, Brand $brand, Prompt $prompt): RedirectResponse
    {
        Gate::authorize('update', $prompt);
        $this->promptOf($brand, $prompt);

        $validated = $request->validate([
            'text' => ['sometimes', 'required', 'string', 'max:2000'],
            'intent' => ['sometimes', 'required', Rule::in(Prompt::INTENTS)],
            'funnel_stage' => ['sometimes', 'required', Rule::in(Prompt::FUNNELS)],
            'priority' => ['sometimes', 'required', Rule::in(Prompt::PRIORITIES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $personal = false;
        DB::transaction(function () use ($brand, $prompt, $validated, &$personal): void {
            $marketId = (int) $prompt->market_id;
            // Same order as the API and MCP: lock the market, then the prompt, then check active capacity.
            Market::query()->where('brand_id', $brand->id)->lockForUpdate()->findOrFail($marketId);
            $locked = Prompt::query()->where('market_id', $marketId)->lockForUpdate()->findOrFail($prompt->id);

            if (! $locked->is_active && ($validated['is_active'] ?? false)) {
                $this->checkActiveCapacity($marketId);
            }

            $changes = $validated;
            if (isset($changes['text'])) {
                $hash = Prompt::hashText($changes['text']);
                $taken = Prompt::query()
                    ->where('market_id', $marketId)
                    ->where('text_hash', $hash)
                    ->whereKeyNot($locked->id)
                    ->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['text' => __('app.library.duplicate')]);
                }
                $changes['text_hash'] = $hash;
                $personal = PersonalData::in($changes['text']);
            }

            $locked->update($changes);
        });

        return back()->with('personal_data', $personal);
    }

    public function import(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('create', Prompt::class);
        $brand->load('markets');

        $validated = $request->validate([
            'file' => ['required_without:csv', 'file', 'max:2048'],
            'csv' => ['required_without:file', 'string', 'max:500000'],
        ]);

        $csv = isset($validated['csv']) ? $validated['csv'] : (string) $request->file('file')?->get();

        return back()->with('prompt_import', PromptCsv::import($brand, $csv));
    }

    public function export(Brand $brand): HttpResponse
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');

        return response(PromptCsv::export($brand), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prompts-'.$brand->id.'.csv"',
        ]);
    }

    public function show(Request $request, Brand $brand, Prompt $prompt, MetricsService $metrics): InertiaResponse
    {
        BrandPage::authorizeView($brand);
        $prompt = $this->promptOf($brand, $prompt);
        $period = BrandPage::period($request);
        $filter = new MetricsFilter($brand->id, $period->from, $period->to, promptIds: [$prompt->id]);
        $from = CarbonImmutable::parse($period->from.' 00:00:00', $period->timezone)->utc();
        $to = CarbonImmutable::parse($period->to.' 23:59:59', $period->timezone)->utc();

        $query = Response::query()
            ->whereHas('run', fn ($run) => $run->where('prompt_id', $prompt->id))
            ->whereBetween('collected_at', [$from, $to])
            ->with([
                'mentions' => fn ($mentions) => $mentions->orderBy('first_char_offset')->orderBy('id'),
                'citations',
            ])
            ->orderByDesc('collected_at');
        $total = (clone $query)->count();
        $responses = $query->limit(100)->get();
        $labels = DashboardLabels::engines($responses->pluck('engine_key')->unique()->values()->all());

        $groups = [];
        foreach ($responses as $response) {
            $key = $response->engine_key;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'engine' => $labels[$key] ?? DashboardLabels::engine($key),
                    'answers' => [],
                ];
            }
            $collected = $response->collected_at;
            $groups[$key]['answers'][] = [
                'id' => $response->id,
                'collected_at' => $collected instanceof CarbonInterface ? $collected->toIso8601String() : '',
                'segments' => AnswerSegments::from((string) $response->answer_text, $response->mentions),
                'citations' => $response->citations->map(fn ($citation): array => [
                    'domain' => (string) $citation->domain,
                    'url' => self::httpUrl($citation->url),
                    'ownership' => DashboardLabels::ownership((string) $citation->ownership),
                ])->all(),
                'raw' => route('brands.responses.raw', [$brand, $response]),
            ];
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
            'period' => ['days' => $period->days],
            'series' => array_map(fn (array $point): array => [
                'period' => $point['period'],
                'visibility' => $point['summary']->visibility->toArray(),
            ], $metrics->series($filter)),
            'engines' => array_values($groups),
            'truncated' => $total > 100,
            'shown' => $responses->count(),
        ]);
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

    private function checkActiveCapacity(int $marketId): void
    {
        $tenantId = app(TenantContext::class)->id();
        $limit = $tenantId === null ? null : Team::query()->find($tenantId)?->plan?->max_prompts_per_market;
        if ($limit !== null && Prompt::query()->where('market_id', $marketId)->where('is_active', true)->count() >= $limit) {
            throw ValidationException::withMessages(['is_active' => __('app.library.quota')]);
        }
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
