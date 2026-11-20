<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Dashboard\BrandPage;
use App\Dashboard\DashboardPeriod;
use App\Dashboard\GraphCache;
use App\Metrics\MetricsService;
use App\Models\BotLogUpload;
use App\Models\Brand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI robots and AI traffic (docs/spec.md 14.2 and 10, docs/ui-forbedring.md 7.6, U09): the funnel «fetched by AI →
 * cited → visits» against the comparison period, fetches per robot over time, AI-referred sessions over time and the
 * most fetched pages. All figures come from MetricsService. Bot and traffic rows only have brand and date dimensions,
 * so the period is the only filter.
 */
class BrandCrawlerController extends Controller
{
    /** Periods longer than this are drawn per ISO week, so a year of data stays readable. */
    private const DAILY_MAX_DAYS = 92;

    public function __invoke(Request $request, Brand $brand, MetricsService $metrics): Response
    {
        BrandPage::authorizeView($brand);
        $query = AnalysisFilters::query($brand, $request->query(), 'brands.crawlers');
        $period = DashboardPeriod::fromInput($query);
        $selection = AnalysisFilters::selection($query);
        $filter = $period->filter($brand->id, ...$selection);
        $previous = $period->filter($brand->id, ...$selection, previous: true);
        $grain = $period->days > self::DAILY_MAX_DAYS ? 'week' : 'day';

        return Inertia::render('Brands/Crawlers', [
            'brand' => BrandPage::payload($brand),
            'period' => [...$period->toArray(), 'grain' => $grain],
            'analysisFilters' => AnalysisFilters::payload($brand, 'brands.crawlers', $query),
            'bots' => $metrics->botHits($filter),
            'traffic' => $metrics->aiTraffic($filter),
            'funnel' => [
                'current' => $metrics->trafficFunnel($filter),
                'previous' => $metrics->trafficFunnel($previous),
            ],
            'connections' => $this->connections($brand),
            'botLabels' => $this->botLabels(),
            'chartData' => Inertia::defer(fn (): array => app(GraphCache::class)->remember(
                'brand.crawlers.u09.'.$grain.'.'.$period->comparison, $filter, $period->timezone, fn (): array => [
                    'bots' => $metrics->botHitsSeries($filter, $grain),
                    'traffic' => $metrics->aiTrafficSeries($filter, $grain),
                    'previous_traffic' => $metrics->aiTrafficSeries($previous, $grain),
                    'pages' => $metrics->botTopPages($filter),
                ],
            )),
        ]);
    }

    /**
     * Whether robot logs (ClickHouse or an uploaded log) and GA4 are set up for the brand, so an empty chart can say
     * why it is empty. Only the existence is sent, never the integration config.
     *
     * @return array{bot_logs: bool, ga4: bool}
     */
    private function connections(Brand $brand): array
    {
        $types = $brand->integrations()->pluck('type')->all();

        return [
            'bot_logs' => in_array('clickhouse_logs', $types, true)
                || BotLogUpload::query()->where('brand_id', $brand->id)->exists(),
            'ga4' => in_array('ga4', $types, true),
        ];
    }

    /**
     * Display names for the stored robot keys: the user-agent token the robot is recognised by.
     *
     * @return array<string, string>
     */
    private function botLabels(): array
    {
        $labels = [];
        foreach (config()->array('ekko.bot_logs.bots') as $agent => $key) {
            if (is_string($agent) && is_string($key) && ! isset($labels[$key])) {
                $labels[$key] = $agent;
            }
        }

        return $labels;
    }
}
