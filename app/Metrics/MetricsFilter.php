<?php

namespace App\Metrics;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The combination of dimensions a metric is computed for (docs/spec.md 9): one brand, a period of tenant-local
 * days (both ends included) and optionally markets, engines, topics, intents, funnel stages and prompts.
 * An empty list means no limit on that dimension.
 */
final class MetricsFilter
{
    public readonly string $from;

    public readonly string $to;

    /**
     * @param  list<int>  $marketIds
     * @param  list<string>  $engineKeys
     * @param  list<int>  $topicIds
     * @param  list<string>  $intents
     * @param  list<string>  $funnelStages
     * @param  list<int>  $promptIds
     */
    public function __construct(
        public readonly int $brandId,
        string|CarbonInterface $from,
        string|CarbonInterface $to,
        public readonly array $marketIds = [],
        public readonly array $engineKeys = [],
        public readonly array $topicIds = [],
        public readonly array $intents = [],
        public readonly array $funnelStages = [],
        public readonly array $promptIds = [],
    ) {
        $this->from = self::date($from);
        $this->to = self::date($to);

        if ($this->from > $this->to) {
            throw new InvalidArgumentException('The period starts after it ends.');
        }
    }

    /**
     * True when the filter limits by something only prompts have (topic, intent, funnel stage). Source figures
     * (agg_source_daily) have no prompt dimension, so they cannot honour these.
     */
    public function limitsPrompts(): bool
    {
        return $this->topicIds !== [] || $this->intents !== [] || $this->funnelStages !== [] || $this->promptIds !== [];
    }

    private static function date(string|CarbonInterface $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->format('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("Not a date (Y-m-d): {$date}");
        }

        return $date;
    }
}
