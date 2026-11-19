<?php

namespace App\Usage;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The one place that decides which usage the platform pays for (ekko.usage.platform_paid_kinds, owner decision on
 * Board #996, ADR 0034). Such usage is booked in usage_events and on the Anthropic account like any other call, but
 * it is not the tenant's: the call quota, the plan budget, the quota alerts, the tenant's usage view and the invoice
 * basis leave it out. The admin usage overview shows it per tenant, and the Anthropic forecast includes it.
 */
final class PlatformPaidUsage
{
    /** @return list<string> */
    public static function kinds(): array
    {
        $kinds = config('ekko.usage.platform_paid_kinds', []);

        return is_array($kinds) ? array_values(array_map(strval(...), $kinds)) : [];
    }

    public static function isPlatformPaid(string $kind): bool
    {
        return in_array($kind, self::kinds(), true);
    }

    /**
     * Keeps the usage that counts for the tenant.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function exclude(EloquentBuilder|QueryBuilder $query, string $column = 'kind'): EloquentBuilder|QueryBuilder
    {
        $kinds = self::kinds();

        return $kinds === [] ? $query : $query->whereNotIn($column, $kinds);
    }

    /**
     * Keeps only the usage the platform pays for.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function only(EloquentBuilder|QueryBuilder $query, string $column = 'kind'): EloquentBuilder|QueryBuilder
    {
        $kinds = self::kinds();

        return $kinds === [] ? $query->whereRaw('false') : $query->whereIn($column, $kinds);
    }
}
