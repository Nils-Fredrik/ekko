<?php

namespace App\Dashboard;

use App\Metrics\MetricsFilter;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A preset or custom tenant-local date window, compared with the previous window or last year.
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
        public readonly string $comparison = 'previous',
    ) {}

    public static function forCurrentTenant(int $days): self
    {
        return self::endingToday($days, self::timezone());
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

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $data = Validator::make($input, [
            'days' => ['sometimes', 'integer', Rule::in(self::LENGTHS)],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'comparison' => ['sometimes', Rule::in(['previous', 'year'])],
        ])->validate();
        $base = self::forCurrentTenant((int) ($data['days'] ?? 28));
        $from = CarbonImmutable::parse($data['from'] ?? $base->from, $base->timezone);
        $to = CarbonImmutable::parse($data['to'] ?? $base->to, $base->timezone);
        $days = (int) $from->diffInDays($to) + 1;
        if ($days > 366) {
            throw ValidationException::withMessages(['to' => __('filters.range_too_long')]);
        }
        $comparison = $data['comparison'] ?? 'previous';
        $previousTo = $comparison === 'year' ? $to->subYearNoOverflow() : $from->subDay();
        $previousFrom = $comparison === 'year' ? $from->subYearNoOverflow() : $previousTo->subDays($days - 1);

        return new self($days, $base->timezone, $from->toDateString(), $to->toDateString(),
            $previousFrom->toDateString(), $previousTo->toDateString(), $comparison);
    }

    /** @return array{days: int, from: string, to: string, previous_from: string, previous_to: string, comparison: string} */
    public function toArray(): array
    {
        return ['days' => $this->days, 'from' => $this->from, 'to' => $this->to,
            'previous_from' => $this->previousFrom, 'previous_to' => $this->previousTo, 'comparison' => $this->comparison];
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

    /**
     * The tenant in the context, including a platform admin acting as a tenant. The stored
     * current_team_id is the admin's own team and is the wrong clock for that session.
     */
    private static function timezone(): string
    {
        $tenantId = app(TenantContext::class)->id();

        if (! is_int($tenantId)) {
            return 'Europe/Oslo';
        }

        $timezone = Team::query()->whereKey($tenantId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Oslo';
    }
}
