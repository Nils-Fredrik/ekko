<?php

namespace App\Dashboard;

use App\Metrics\MetricsFilter;
use App\Models\AggDaily;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
        public readonly ?string $measuredFrom = null,
        public readonly bool $automatic = false,
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

    /**
     * Longest preset whose length is at least 80 % covered by days that have agg_daily rows.
     * 23 days selects 28, and 72 days selects 90 (docs/ux-arbeidsflate.md 7).
     */
    public static function lengthForHistory(int $daysWithData): int
    {
        if ($daysWithData >= 72) {
            return 90;
        }

        return $daysWithData >= 23 ? 28 : 7;
    }

    public static function daysWithData(int $brandId, ?string $from = null, ?string $to = null): int
    {
        $query = AggDaily::query()->where('brand_id', $brandId);
        if ($from !== null && $to !== null) {
            $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
        }

        return (int) $query->distinct()->count('date');
    }

    /**
     * Longest preset whose own window, ending today, is at least 80 % covered.
     * Days outside that window do not count, so a long gap does not stretch the period.
     */
    public static function coveringLength(int $brandId): int
    {
        $zone = self::timezone();
        foreach ([90 => 72, 28 => 23] as $length => $needed) {
            $window = self::endingToday($length, $zone);
            if (self::daysWithData($brandId, $window->from, $window->to) >= $needed) {
                return $length;
            }
        }

        return 7;
    }

    public static function measuredFrom(int $brandId): ?string
    {
        $date = AggDaily::query()->where('brand_id', $brandId)->min('date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    /** @param array<string, mixed> $input */
    public static function automatic(array $input): bool
    {
        return ! array_key_exists('days', $input) && empty($input['from']) && empty($input['to']);
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, ?int $brandId = null): self
    {
        $data = Validator::make($input, [
            'days' => ['sometimes', 'integer', Rule::in(self::LENGTHS)],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'comparison' => ['sometimes', Rule::in(['previous', 'year'])],
        ])->validate();
        $automatic = self::automatic($input);
        $history = $brandId !== null ? self::history($brandId) : null;
        $days = $automatic && $history !== null
            ? $history['length']
            : (int) ($data['days'] ?? 28);
        $base = self::forCurrentTenant($days);
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
            $previousFrom->toDateString(), $previousTo->toDateString(), $comparison,
            $history['from'] ?? null, $automatic);
    }

    /**
     * The chosen length is cached for the tenant-local day, including deferred insight and chart requests,
     * so a partial reload uses the same window without scanning the aggregates again.
     *
     * @return array{length: int, from: ?string}
     */
    public static function history(int $brandId): array
    {
        $today = CarbonImmutable::now(self::timezone())->toDateString();
        /** @var array{length: int, from: ?string} $history */
        $history = Cache::remember("brand-coverage:{$brandId}:{$today}", 60, fn (): array => [
            'length' => self::coveringLength($brandId),
            'from' => self::measuredFrom($brandId),
        ]);

        return $history;
    }

    /** @return array{days: int, from: string, to: string, previous_from: string, previous_to: string, comparison: string} */
    public function toArray(): array
    {
        return ['days' => $this->days, 'from' => $this->from, 'to' => $this->to,
            'previous_from' => $this->previousFrom, 'previous_to' => $this->previousTo, 'comparison' => $this->comparison,
            'measured_from' => $this->measuredFrom, 'automatic' => $this->automatic];
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
