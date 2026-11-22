<?php

namespace App\Alerts;

use App\Models\AlertRule;
use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a detector needs for one tenant: the time, the tenant-local days of the comparison windows, the
 * active brands and the alert rules. Rule rows are created with the defaults of config/ekko.php the first
 * time a type is evaluated for a brand.
 */
final class AlertContext
{
    /** @var array<string, AlertRule> */
    private array $rules = [];

    /**
     * @param  Collection<int, Brand>  $brands  Active brands.
     */
    public function __construct(
        public readonly CarbonImmutable $now,
        public readonly CarbonImmutable $localNow,
        public readonly Collection $brands,
    ) {}

    /** Today's date in the tenant's timezone. */
    public function today(): CarbonImmutable
    {
        return $this->localNow->startOfDay();
    }

    /**
     * The current window: the days ending yesterday (today is not complete).
     *
     * @return array{0: string, 1: string} first and last day, Y-m-d
     */
    public function currentWindow(): array
    {
        $days = (int) config('ekko.alerts.window_days');

        return [$this->today()->subDays($days)->toDateString(), $this->today()->subDay()->toDateString()];
    }

    /**
     * The window before the current one, same length.
     *
     * @return array{0: string, 1: string}
     */
    public function previousWindow(): array
    {
        $days = (int) config('ekko.alerts.window_days');

        return [$this->today()->subDays(2 * $days)->toDateString(), $this->today()->subDays($days + 1)->toDateString()];
    }

    /**
     * The active rule for the type (and brand), created with the defaults when missing. Null when the tenant
     * has turned the rule off.
     */
    public function rule(AlertType $type, ?int $brandId): ?AlertRule
    {
        $brandId = $type->isTenantWide() && $type !== AlertType::IntegrationError ? null : $brandId;
        $key = $type->value.':'.($brandId ?? '');

        $this->rules[$key] ??= app(AlertRules::class)->forSource($type, $brandId);

        return $this->rules[$key]->is_active ? $this->rules[$key] : null;
    }
}
