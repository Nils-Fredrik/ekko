<?php

namespace App\Dashboard;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shared pieces of the brand pages (docs/spec.md 14.2): the brand payload, the period and a market
 * filter that is ignored unless the market belongs to the brand.
 */
final class BrandPage
{
    public static function authorizeView(Brand $brand): void
    {
        Gate::authorize('view', $brand);
    }

    /**
     * @return array{id: int, name: string}
     */
    public static function payload(Brand $brand): array
    {
        return ['id' => $brand->id, 'name' => $brand->name];
    }

    public static function period(Request $request): DashboardPeriod
    {
        $days = $request->filled('days') ? $request->integer('days') : 28;

        return DashboardPeriod::forCurrentTenant(in_array($days, DashboardPeriod::LENGTHS, true) ? $days : 28);
    }

    /**
     * @return list<int>
     */
    public static function marketIds(Brand $brand, Request $request): array
    {
        if (! $request->filled('market')) {
            return [];
        }

        $market = $brand->markets->firstWhere('id', $request->integer('market'));

        return $market === null ? [] : [(int) $market->id];
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public static function markets(Brand $brand): array
    {
        return $brand->markets->map(fn ($market) => [
            'id' => $market->id,
            'label' => $market->location_label ?: strtoupper($market->country),
        ])->values()->all();
    }
}
