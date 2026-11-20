<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Dashboard\DashboardPeriod;
use App\Dashboard\GraphCache;
use App\Dashboard\SourcePage;
use App\Models\Brand;
use App\Models\MarketEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cited domains by ownership, the source gap with its actions and the citations by source type (docs/spec.md 14.2,
 * docs/ui-forbedring.md 7.5, U08). The figures come from MetricsService through SourcePage. Source rows have no
 * prompt dimension, so topic and intent are not filters here. A clicked domain (?domain=) lists the answers that
 * cite it.
 */
class BrandSourceController extends Controller
{
    public function __invoke(Request $request, Brand $brand, SourcePage $page): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');
        $query = AnalysisFilters::query($brand, $request->query(), 'brands.sources');
        $period = DashboardPeriod::fromInput($query);
        $selection = AnalysisFilters::selection($query);
        $marketIds = $selection['marketIds'];
        $engineKeys = $selection['engineKeys'];
        $filter = $period->filter($brand->id, ...$selection);

        $domain = $this->domain($request);

        return Inertia::render('Brands/Sources', [
            'brand' => BrandPage::payload($brand),
            'period' => $period->toArray(),
            'analysisFilters' => AnalysisFilters::payload($brand, 'brands.sources', $query),
            'filters' => [
                'market' => $marketIds[0] ?? null,
                'engine' => $engineKeys[0] ?? null,
                'domain' => $domain,
            ],
            'markets' => BrandPage::markets($brand),
            'engines' => $this->engines($brand),
            // Evaluated on the first visit and on the partial reload when a domain is clicked.
            'citing' => fn (): ?array => $domain === null ? null : $page->citing($brand, $filter, $domain),
            'chartData' => Inertia::defer(fn (): array => app(GraphCache::class)->remember(
                'brand.sources.u08', $filter, $period->timezone, fn (): array => $page->chartData($brand, $filter),
            )),
        ]);
    }

    /** The clicked domain, as stored in citations (lower case); null without one. */
    private function domain(Request $request): ?string
    {
        $domain = mb_strtolower(trim($request->string('domain')->toString()));

        return $domain === '' || mb_strlen($domain) > 255 ? null : $domain;
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
