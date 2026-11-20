<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Dashboard\BrandPage;
use App\Dashboard\DashboardPeriod;
use App\Dashboard\GraphCache;
use App\Metrics\MetricsService;
use App\Models\Brand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI bot hits and AI referral traffic (docs/spec.md 14.2 and 10). Both come from MetricsService.
 */
class BrandCrawlerController extends Controller
{
    public function __invoke(Request $request, Brand $brand, MetricsService $metrics): Response
    {
        BrandPage::authorizeView($brand);
        $query = AnalysisFilters::query($brand, $request->query(), 'brands.crawlers');
        $period = DashboardPeriod::fromInput($query);
        $selection = AnalysisFilters::selection($query);
        $filter = $period->filter($brand->id, ...$selection);

        return Inertia::render('Brands/Crawlers', [
            'brand' => BrandPage::payload($brand),
            'period' => $period->toArray(),
            'analysisFilters' => AnalysisFilters::payload($brand, 'brands.crawlers', $query),
            'bots' => $metrics->botHits($filter),
            'traffic' => $metrics->aiTraffic($filter),
            'chartData' => Inertia::defer(fn (): array => app(GraphCache::class)->remember(
                'brand.crawlers', $filter, $period->timezone, fn (): array => [
                    'bots' => $metrics->botHitsSeries($filter),
                    'traffic' => $metrics->aiTrafficSeries($filter),
                    'funnel' => $metrics->trafficFunnel($filter),
                ],
            )),
        ]);
    }
}
