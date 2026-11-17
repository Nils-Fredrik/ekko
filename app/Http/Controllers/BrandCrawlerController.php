<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Metrics\MetricsFilter;
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
        $period = BrandPage::period($request);
        $filter = new MetricsFilter($brand->id, $period->from, $period->to);

        return Inertia::render('Brands/Crawlers', [
            'brand' => BrandPage::payload($brand),
            'period' => ['days' => $period->days],
            'bots' => $metrics->botHits($filter),
            'traffic' => $metrics->aiTraffic($filter),
        ]);
    }
}
