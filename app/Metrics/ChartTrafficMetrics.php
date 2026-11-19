<?php

namespace App\Metrics;

use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
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
