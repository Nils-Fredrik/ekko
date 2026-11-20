<?php

namespace App\Metrics;

use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Tenant-scoped daily aggregates for robot and AI traffic charts. */
trait ChartTrafficMetrics
{
    /**
     * Aggregated hits per robot and period, summing across the brand's domains.
     * Missing dates are omitted rather than presented as observed zeroes.
     *
     * @return list<array{period: string, bot: string, hits: int, n: int, low_sample: bool}>
     */
    public function botHitsSeries(MetricsFilter $filter, string $grain = 'day'): array
    {
        $this->ensureTrafficFilter($filter);
        $period = $this->trafficPeriod($grain);

        return BotHitDaily::query()
            ->where('brand_id', $filter->brandId)
            ->whereBetween('date', [$filter->from, $filter->to])
            ->toBase()
            ->selectRaw("{$period} as period, bot, sum(hits) as hits")
            ->groupByRaw("{$period}, bot")
            ->orderBy('period')->orderBy('bot')->get()
            ->map(fn (object $row): array => [
                'period' => substr((string) $row->period, 0, 10),
                'bot' => (string) $row->bot,
                'hits' => (int) $row->hits,
                'n' => (int) $row->hits,
                'low_sample' => (int) $row->hits < 10,
            ])->all();
    }

    /**
     * AI referrals per source and period. The sample size is the number of sessions.
     *
     * @return list<array{period: string, source: string, sessions: int, engaged_sessions: int, conversions: float, n: int, low_sample: bool}>
     */
    public function aiTrafficSeries(MetricsFilter $filter, string $grain = 'day'): array
    {
        $this->ensureTrafficFilter($filter);
        $period = $this->trafficPeriod($grain);

        return AiReferralDaily::query()
            ->where('brand_id', $filter->brandId)
            ->whereBetween('date', [$filter->from, $filter->to])
            ->toBase()
            ->selectRaw("{$period} as period, source, sum(sessions) as sessions, sum(engaged_sessions) as engaged_sessions, sum(conversions) as conversions")
            ->groupByRaw("{$period}, source")
            ->orderBy('period')->orderBy('source')->get()
            ->map(fn (object $row): array => [
                'period' => substr((string) $row->period, 0, 10),
                'source' => (string) $row->source,
                'sessions' => (int) $row->sessions,
                'engaged_sessions' => (int) $row->engaged_sessions,
                'conversions' => (float) $row->conversions,
                'n' => (int) $row->sessions,
                'low_sample' => (int) $row->sessions < 10,
            ])->all();
    }

    /**
     * The pages AI robots fetched most in the period, per domain and path. Each daily row keeps only its ten
     * most fetched paths (per domain and robot), so a page's hits are the sum of the days it made that list:
     * a lower bound, never an estimate. `bots` is the number of robots that fetched the page.
     *
     * @return list<array{domain: string, path: string, hits: int, bots: int}>
     */
    public function botTopPages(MetricsFilter $filter, int $limit = 20): array
    {
        $this->ensureTrafficFilter($filter);

        return BotHitDaily::query()
            ->where('bot_hits_daily.brand_id', $filter->brandId)
            ->whereBetween('bot_hits_daily.date', [$filter->from, $filter->to])
            ->toBase()
            ->crossJoin(DB::raw('lateral jsonb_array_elements(bot_hits_daily.top_paths) as page(item)'))
            ->selectRaw("bot_hits_daily.domain, page.item->>'path' as path, sum((page.item->>'hits')::bigint) as hits, count(distinct bot_hits_daily.bot) as bots")
            ->groupByRaw("bot_hits_daily.domain, page.item->>'path'")
            ->orderByDesc('hits')->orderBy('domain')->orderBy('path')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'domain' => (string) $row->domain,
                'path' => (string) $row->path,
                'hits' => (int) $row->hits,
                'bots' => (int) $row->bots,
            ])->all();
    }

    /** Those tables only have brand and date dimensions; silently ignoring a filter is misleading. */
    private function ensureTrafficFilter(MetricsFilter $filter): void
    {
        if ($filter->marketIds !== [] || $filter->engineKeys !== [] || $filter->limitsPrompts()) {
            throw new InvalidArgumentException('Traffic figures cannot be limited by market, engine or prompt dimensions.');
        }
    }

    private function trafficPeriod(string $grain): string
    {
        if (! in_array($grain, ['day', 'week', 'month'], true)) {
            throw new InvalidArgumentException("Unknown grain: {$grain}");
        }

        // Match MetricsService::series: ISO weeks start on Monday and edge buckets may be partial.
        return $grain === 'day' ? 'date' : "date_trunc('{$grain}', date)::date";
    }
}
