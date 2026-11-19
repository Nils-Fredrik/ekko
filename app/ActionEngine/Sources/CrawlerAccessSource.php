<?php

namespace App\ActionEngine\Sources;

use App\ActionEngine\ActionCandidate;
use App\ActionEngine\ActionSource;
use App\ActionEngine\GenerationContext;
use App\ActionEngine\SourceOutcome;
use App\Enums\ActionType;
use App\Metrics\Formulas;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\BotHitDaily;

/**
 * crawler_access (docs/spec.md 10.1 and 11.3): an AI search bot that has not fetched the brand's pages in the
 * latest ekko.actions.crawler_access.window_days days, while the brand's logs show other AI bots in the
 * same window (so the logs are flowing). Brand-level: the action has no market. Without bot data for the
 * brand (no ClickHouse integration or upload, or nothing synced) the source does not judge the brand.
 */
final readonly class CrawlerAccessSource implements ActionSource
{
    public function __construct(private MetricsService $metrics) {}

    public function type(): ActionType
    {
        return ActionType::CrawlerAccess;
    }

    public function evaluate(GenerationContext $context): SourceOutcome
    {
        $brand = $context->brand;
        $windowDays = (int) config('ekko.actions.window_days');
        $days = (int) config('ekko.actions.crawler_access.window_days');
        [$from, $to] = $context->window($days);

        $recent = [];
        foreach ($this->metrics->botHits(new MetricsFilter($brand->id, $from, $to)) as $row) {
            $recent[$row['bot']] = $row['hits'];
        }
        // Only when other AI bots fetched the pages in the same window do the logs flow; a stopped sync or upload
        // is not a bot that does not fetch.
        if (array_sum($recent) === 0) {
            return new SourceOutcome([], []);
        }
        $labels = array_flip((array) config('ekko.bot_logs.bots'));
        // The whole brand's answers: a bot that does not fetch the pages affects every market.
        $reach = $this->metrics->reach(new MetricsFilter($brand->id, ...$context->window($windowDays)));

        $candidates = [];
        foreach ((array) config('ekko.actions.crawler_access.bots') as $bot) {
            $bot = (string) $bot;
            if (($recent[$bot] ?? 0) > 0) {
                continue;
            }
            $lastSeen = BotHitDaily::query()->where('brand_id', $brand->id)->where('bot', $bot)->where('hits', '>', 0)->max('date');
            $label = (string) ($labels[$bot] ?? $bot);

            $candidates[] = new ActionCandidate(
                type: ActionType::CrawlerAccess,
                marketId: null,
                subject: $bot,
                evidence: [
                    'period' => ['from' => $from, 'to' => $to, 'days' => $days],
                    'bot' => $bot,
                    'user_agent' => $label,
                    'hits' => 0,
                    'other_bot_hits' => array_sum($recent),
                    'last_hit_on' => $lastSeen === null ? null : substr((string) $lastSeen, 0, 10),
                    'responses' => $reach['responses'],
                    'competitor_only_responses' => $reach['competitor_only_responses'],
                ],
                affectedPerMonth: GenerationContext::perMonth($reach['responses'], $windowDays),
                competitorOnlyShare: (float) (Formulas::ratio($reach['competitor_only_responses'], $reach['responses']) ?? 0.0),
                textParams: [
                    'bot' => $label,
                    'days' => $days,
                    'other_hits' => array_sum($recent),
                ],
            );
        }

        return new SourceOutcome([null], $candidates);
    }
}
