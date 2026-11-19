<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Dashboard\GraphCache;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\MarketEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cited domains, source share and the source gap (docs/spec.md 14.2, T27). Both figures come from
 * MetricsService. Source rows have no prompt dimension, so topic and intent are not filters here.
 */
class BrandSourceController extends Controller
{
    public function __invoke(Request $request, Brand $brand, MetricsService $metrics): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');
        $period = BrandPage::period($request);
        $marketIds = BrandPage::marketIds($brand, $request);
        $engineKeys = $this->engineKeys($brand, $request);
        $filter = new MetricsFilter($brand->id, $period->from, $period->to, marketIds: $marketIds, engineKeys: $engineKeys);

        return Inertia::render('Brands/Sources', [
            'brand' => BrandPage::payload($brand),
            'period' => ['days' => $period->days],
            'filters' => [
                'market' => $marketIds[0] ?? null,
                'engine' => $engineKeys[0] ?? null,
            ],
            'markets' => BrandPage::markets($brand),
            'engines' => $this->engines($brand),
            'share' => array_map(fn (array $row): array => [
                ...$row,
                'low_sample' => $row['n'] < 10,
            ], $metrics->sourceShare($filter)),
            'gap' => array_map(fn (array $row): array => [
                ...$row,
                'low_sample' => $row['n'] < 10,
            ], $metrics->sourceGap($filter)),
            'chartData' => Inertia::defer(fn (): array => app(GraphCache::class)->remember(
                'brand.sources', $filter, $period->timezone, fn (): array => [
                    'distribution' => $metrics->citationDistribution($filter),
                    'share' => $metrics->sourceShare($filter),
                    'gap' => $metrics->sourceGap($filter),
                ],
            )),
        ]);
    }

    /**
     * @return list<string>
     */
    private function engineKeys(Brand $brand, Request $request): array
    {
        $engine = $request->string('engine')->toString();
        if ($engine === '') {
            return [];
        }

        $known = MarketEngine::query()
            ->whereIn('market_id', $brand->markets->pluck('id'))
            ->where('engine_key', $engine)
            ->exists();

        return $known ? [$engine] : [];
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    private function engines(Brand $brand): array
    {
        $keys = MarketEngine::query()
            ->whereIn('market_id', $brand->markets->pluck('id'))
            ->distinct()
            ->orderBy('engine_key')
            ->pluck('engine_key')
            ->all();
        $labels = DashboardLabels::engines($keys);

        return array_map(fn (string $key): array => [
            'key' => $key,
            'name' => $labels[$key] ?? DashboardLabels::engine($key),
        ], $keys);
    }
}
