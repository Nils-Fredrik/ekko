<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardLabels;
use App\Dashboard\DashboardPeriod;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Metrics\MetricsSummary;
use App\Models\Brand;
use App\Models\MarketEngine;
use App\Models\Prompt;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The brand list and the brand dashboard (docs/spec.md 14.2). Filters are market, engine, topic,
 * intent and a period of 7, 28 or 90 days. Every figure comes from MetricsService.
 */
class BrandController extends Controller
{
    public function index(): Response
    {
        if (! Gate::allows('viewAny', Brand::class)) {
            abort_unless(app(TenantContext::class)->id() === null, 403);

            return Inertia::render('Brands/Index', ['brands' => []]);
        }

        $brands = Brand::query()->with('markets')->orderBy('name')->get()->map(fn (Brand $brand) => [
            'id' => $brand->id,
            'name' => $brand->name,
            'status' => DashboardLabels::status($brand->status),
            'markets' => $brand->markets->map(fn ($market) => [
                'id' => $market->id,
                'label' => $market->location_label ?: strtoupper($market->country),
                'active' => $market->is_active,
            ])->all(),
        ])->all();

        return Inertia::render('Brands/Index', ['brands' => $brands]);
    }

    public function show(Request $request, Brand $brand, MetricsService $metrics): Response
    {
        Gate::authorize('view', $brand);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in(DashboardPeriod::LENGTHS)],
            'market' => ['sometimes', 'nullable', 'integer'],
            'engine' => ['sometimes', 'nullable', 'string'],
            'topic' => ['sometimes', 'nullable', 'integer'],
            'intent' => ['sometimes', 'nullable', 'string'],
        ]);

        $brand->load(['markets', 'topics', 'competitors']);
        $period = DashboardPeriod::forCurrentTenant((int) ($validated['days'] ?? 28));
        $selection = $this->selection($brand, $validated);
        $filter = $period->filter($brand->id, ...$selection);
        $previous = $period->filter($brand->id, ...$selection, previous: true);
        $summary = $metrics->summary($filter);

        return Inertia::render('Brands/Show', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'status' => DashboardLabels::status($brand->status),
            ],
            'period' => [
                'days' => $period->days,
                'from' => $period->from,
                'to' => $period->to,
                'previous_from' => $period->previousFrom,
                'previous_to' => $period->previousTo,
            ],
            'filters' => [
                'market' => $selection['marketIds'][0] ?? null,
                'engine' => $selection['engineKeys'][0] ?? null,
                'topic' => $selection['topicIds'][0] ?? null,
                'intent' => $selection['intents'][0] ?? null,
            ],
            'options' => $this->options($brand),
            'summary' => $summary->toArray(),
            'previous' => $metrics->summary($previous)->toArray(),
            'series' => array_map(fn (array $point) => [
                'period' => $point['period'],
                'visibility' => $point['summary']->visibility->toArray(),
            ], $metrics->series($filter)),
            'voice' => $this->voice($metrics, $filter, $brand),
            'engines' => $this->engines($metrics, $filter),
            'modelChanges' => $this->modelChanges($metrics, $period, $selection['engineKeys']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{marketIds: list<int>, engineKeys: list<string>, topicIds: list<int>, intents: list<string>}
     */
    private function selection(Brand $brand, array $validated): array
    {
        $marketIds = [];
        if (isset($validated['market'])) {
            $market = $brand->markets->firstWhere('id', (int) $validated['market']);
            if ($market !== null) {
                $marketIds = [$market->id];
            }
        }

        $engineKeys = [];
        if (isset($validated['engine']) && is_string($validated['engine']) && $validated['engine'] !== '') {
            $known = MarketEngine::query()
                ->whereIn('market_id', $brand->markets->pluck('id'))
                ->where('engine_key', $validated['engine'])
                ->exists();
            if ($known) {
                $engineKeys = [$validated['engine']];
            }
        }

        $topicIds = [];
        if (isset($validated['topic'])) {
            $topic = $brand->topics->firstWhere('id', (int) $validated['topic']);
            if ($topic !== null) {
                $topicIds = [$topic->id];
            }
        }

        $intents = [];
        if (isset($validated['intent']) && is_string($validated['intent']) && $validated['intent'] !== '') {
            $known = Prompt::query()
                ->whereIn('market_id', $brand->markets->pluck('id'))
                ->where('intent', $validated['intent'])
                ->exists();
            if ($known) {
                $intents = [$validated['intent']];
            }
        }

        return compact('marketIds', 'engineKeys', 'topicIds', 'intents');
    }

    /**
     * @return array{markets: list<array{id: int, label: string}>, engines: list<array{key: string, name: string}>, topics: list<array{id: int, name: string}>, intents: list<array{value: string, label: string}>}
     */
    private function options(Brand $brand): array
    {
        $marketIds = $brand->markets->pluck('id');
        $engineKeys = MarketEngine::query()->whereIn('market_id', $marketIds)->distinct()->orderBy('engine_key')->pluck('engine_key');
        $engineLabels = DashboardLabels::engines($engineKeys->all());

        return [
            'markets' => $brand->markets->map(fn ($market) => [
                'id' => $market->id,
                'label' => $market->location_label ?: strtoupper($market->country),
            ])->values()->all(),
            'engines' => $engineKeys->map(fn ($key) => [
                'key' => $key,
                'name' => $engineLabels[$key] ?? DashboardLabels::engine($key),
            ])->all(),
            'topics' => $brand->topics->map(fn ($topic) => ['id' => $topic->id, 'name' => $topic->name])->values()->all(),
            'intents' => Prompt::query()
                ->whereIn('market_id', $marketIds)
                ->whereNotNull('intent')
                ->distinct()
                ->orderBy('intent')
                ->pluck('intent')
                ->map(fn ($intent) => ['value' => $intent, 'label' => DashboardLabels::intent($intent)])
                ->all(),
        ];
    }

    /**
     * @return list<array{id: string, name: string, mentions: int, share: float|null, n: int, low_sample: bool}>
     */
    private function voice(MetricsService $metrics, MetricsFilter $filter, Brand $brand): array
    {
        return array_map(function (array $part) use ($brand) {
            $part['name'] = $part['name'] ?? $brand->name;
            $part['low_sample'] = $part['n'] < 10;

            return $part;
        }, $metrics->voiceParts($filter));
    }

    /**
     * @return list<array{key: string, name: string, visibility: array{value: float|null, n: int, low_sample: bool}, share_of_voice: array{value: float|null, n: int, low_sample: bool}}>
     */
    private function engines(MetricsService $metrics, MetricsFilter $filter): array
    {
        $rows = $metrics->breakdown($filter, 'engine');
        $labels = DashboardLabels::engines(array_keys($rows));

        return array_map(fn (string $key, MetricsSummary $summary) => [
            'key' => $key,
            'name' => $labels[$key] ?? DashboardLabels::engine($key),
            'visibility' => $summary->visibility->toArray(),
            'share_of_voice' => $summary->shareOfVoice->toArray(),
        ], array_keys($rows), array_values($rows));
    }

    /**
     * @param  list<string>  $engineKeys
     * @return list<array{engine_key: string, engine: string, changed_on: string, from_model: string, to_model: string}>
     */
    private function modelChanges(MetricsService $metrics, DashboardPeriod $period, array $engineKeys): array
    {
        $changes = $metrics->modelChanges($period->from, $period->to, $engineKeys);
        $labels = DashboardLabels::engines($changes->pluck('engine_key')->all());

        return $changes->map(fn ($change) => [
            'engine_key' => $change->engine_key,
            'engine' => $labels[$change->engine_key] ?? DashboardLabels::engine($change->engine_key),
            'changed_on' => $change->changed_on->toDateString(),
            'from_model' => $change->from_model,
            'to_model' => $change->to_model,
        ])->all();
    }
}
