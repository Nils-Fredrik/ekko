<?php

namespace App\Dashboard;

use App\Metrics\MetricsFilter;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A dashboard window of 7, 28 or 90 tenant-local days ending today, plus the window of the same
 * length immediately before it. The dashboard compares the two and never stores the windows.
 */
final class DashboardPeriod
{
    /** @var list<int> */
    public const LENGTHS = [7, 28, 90];

    public function __construct(
        public readonly int $days,
        public readonly string $timezone,
        public readonly string $from,
        public readonly string $to,
        public readonly string $previousFrom,
        public readonly string $previousTo,
    ) {}

    public static function forUser(?User $user, int $days): self
    {
        return self::endingToday($days, self::timezone($user));
    }

    public static function endingToday(int $days, string $timezone): self
    {
        if (! in_array($days, self::LENGTHS, true)) {
            $days = 28;
        }

        $to = CarbonImmutable::now($timezone)->startOfDay();
        $from = $to->subDays($days - 1);
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        return new self(
            $days,
            $timezone,
            $from->toDateString(),
            $to->toDateString(),
            $previousFrom->toDateString(),
            $previousTo->toDateString(),
        );
    }

    /**
     * @param  list<int>  $marketIds
     * @param  list<string>  $engineKeys
     * @param  list<int>  $topicIds
     * @param  list<string>  $intents
     */
    public function filter(int $brandId, array $marketIds = [], array $engineKeys = [], array $topicIds = [], array $intents = [], bool $previous = false): MetricsFilter
    {
        return new MetricsFilter(
            brandId: $brandId,
            from: $previous ? $this->previousFrom : $this->from,
            to: $previous ? $this->previousTo : $this->to,
            marketIds: $marketIds,
            engineKeys: $engineKeys,
            topicIds: $topicIds,
            intents: $intents,
        );
    }

    private static function timezone(?User $user): string
    {
        $teamId = $user?->current_team_id;

        if (! is_int($teamId)) {
            return 'Europe/Oslo';
        }

        $timezone = Team::query()->whereKey($teamId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Oslo';
    }
}
