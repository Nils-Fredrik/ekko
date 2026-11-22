<?php

namespace App\Dashboard;

use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\MarketEngine;
use App\Models\Prompt;
use App\Models\SavedView;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Canonical filters shared by each brand analysis page and saved views (U11). */
final class AnalysisFilters
{
    public const PAGES = ['brands.show', 'brands.prompts.index', 'brands.prompts.show', 'brands.competitors.index', 'brands.responses.index', 'brands.sources', 'brands.crawlers'];

    /** @return list<string> */
    public static function dimensions(string $page): array
    {
        return match ($page) {
            'brands.crawlers' => [],
            'brands.sources' => ['market', 'engine'],
            'brands.prompts.show' => ['engine'],
            'brands.show', 'brands.competitors.index' => ['market', 'engine', 'topic', 'intent', 'competitor'],
            default => ['market', 'engine', 'topic', 'intent'],
        };
    }

    /** @return array<string, list<array{value: int|string, label: string}>> */
    public static function options(Brand $brand, string $page): array
    {
        $brand->loadMissing(['markets', 'topics', 'competitors']);
        $engines = MarketEngine::query()->whereIn('market_id', $brand->markets->pluck('id'))->distinct()->orderBy('engine_key')->pluck('engine_key')->all();
        $labels = DashboardLabels::engines($engines);
        $options = [
            'market' => $brand->markets->map(fn ($m): array => ['value' => $m->id, 'label' => $m->location_label ?: strtoupper($m->country)])->all(),
            'engine' => array_map(fn (string $key): array => ['value' => $key, 'label' => $labels[$key] ?? $key], $engines),
            'topic' => $brand->topics->map(fn ($t): array => ['value' => $t->id, 'label' => $t->name])->all(),
            'intent' => array_map(fn (string $i): array => ['value' => $i, 'label' => DashboardLabels::intent($i)], Prompt::INTENTS),
            'competitor' => $brand->competitors->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all(),
        ];

        return array_intersect_key($options, array_flip(self::dimensions($page)));
    }

    /** @param array<string, mixed> $input
     * @return array<string, int|string>
     */
    public static function query(Brand $brand, array $input, string $page): array
    {
        $data = Validator::make($input, [
            'market' => ['nullable', 'integer'], 'engine' => ['nullable', 'string', 'max:100'],
            'topic' => ['nullable', 'integer'], 'intent' => ['nullable', 'string', 'max:100'],
            'competitor' => ['nullable', 'integer'],
            'grain' => $page === 'brands.show' ? ['sometimes', Rule::in(['day', 'week', 'month'])] : ['exclude'],
        ])->validate();
        $period = DashboardPeriod::fromInput($input, $brand->id);
        $query = ['from' => $period->from, 'to' => $period->to, 'comparison' => $period->comparison];
        if (empty($input['from']) && empty($input['to'])) {
            unset($query['from'], $query['to']);
            $query['days'] = $period->days;
        }
        if ($page === 'brands.show') {
            $query['grain'] = $data['grain'] ?? 'day';
        }
        foreach (self::options($brand, $page) as $key => $options) {
            foreach ($options as $option) {
                if (isset($data[$key]) && (string) $data[$key] === (string) $option['value']) {
                    $query[$key] = $option['value'];
                    break;
                }
            }
        }

        return $query;
    }

    /** @param array<string, int|string> $query
     * @return array{marketIds: list<int>, engineKeys: list<string>, topicIds: list<int>, intents: list<string>}
     */
    public static function selection(array $query): array
    {
        return ['marketIds' => isset($query['market']) ? [(int) $query['market']] : [],
            'engineKeys' => isset($query['engine']) ? [(string) $query['engine']] : [],
            'topicIds' => isset($query['topic']) ? [(int) $query['topic']] : [],
            'intents' => isset($query['intent']) ? [(string) $query['intent']] : []];
    }

    /** @return list<array{key: string, label: string, value: float|null, n: int, low_sample: bool, group: string}> */
    public static function topicBars(Brand $brand, MetricsService $metrics, MetricsFilter $filter): array
    {
        $summaries = $metrics->breakdown($filter, 'topic');
        $bars = [];
        foreach ($brand->topics as $topic) {
            $key = (string) $topic->id;
            if (isset($summaries[$key])) {
                $bars[] = ['key' => (string) $topic->id, 'label' => $topic->name,
                    ...$summaries[$key]->visibility->toArray(), 'group' => 'own'];
            }
        }

        return $bars;
    }

    /**
     * @param  array<string, int|string>  $query
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function payload(Brand $brand, string $page, array $query, array $input = []): array
    {
        return ['query' => $query, 'options' => self::options($brand, $page),
            'views' => SavedView::query()->where('user_id', auth()->id())->where('brand_id', $brand->id)->where('page', $page)
                ->orderBy('name')->get(['id', 'name', 'filters'])->toArray(),
            'automaticPeriod' => DashboardPeriod::automatic($input)];
    }
}
