<?php

namespace App\Workspace;

use App\Enums\TenantPermission;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use App\Usage\MonthlyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The `workspace` prop for the sidebar (docs/ux-arbeidsflate.md 4.1, U17): the tenant's brands, the brand and
 * market in scope, badge counts, the month's usage and what the role may change. The brand comes from the
 * route parameter {brand} and the market from ?market, so a copied URL gives the same scope.
 */
final class Workspace
{
    /**
     * @return array{brands: list<array{id: int, name: string}>, brand: array{id: int, name: string}|null, markets: list<array{id: int, label: string, is_active: bool}>, market_id: int|null, counts: array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}, usage: array{month: string, calls: int, quota: int|null, cost_usd: string, percent: float|null}|null, role: string|null, can: array<string, bool>}|null
     */
    public static function for(Request $request): ?array
    {
        $user = $request->user();
        $tenantId = app(TenantContext::class)->id();
        if (! $user instanceof User || $tenantId === null) {
            return null;
        }
        $team = $user->currentTeam instanceof Team && $user->currentTeam->id === $tenantId
            ? $user->currentTeam
            : Team::query()->find($tenantId);
        if (! $team instanceof Team) {
            return null;
        }
        $access = app(TenantAccess::class);
        if (! $access->can($user, $team, TenantPermission::ViewData)) {
            return null;
        }

        $brand = self::brand($request);
        $markets = $brand?->markets()->orderBy('id')->get() ?? collect();
        $marketId = filter_var($request->query('market'), FILTER_VALIDATE_INT);
        $market = $marketId === false ? null : $markets->firstWhere('id', $marketId);
        $can = static fn (TenantPermission $permission): bool => $access->can($user, $team, $permission);
        $role = $access->roleIn($user, $team);

        return [
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Brand $item): array => ['id' => $item->id, 'name' => $item->name])->all(),
            'brand' => $brand === null ? null : ['id' => $brand->id, 'name' => $brand->name],
            'markets' => $markets->map(fn (Market $item): array => [
                'id' => $item->id, 'label' => self::marketLabel($item), 'is_active' => $item->is_active,
            ])->all(),
            'market_id' => $market?->id,
            'counts' => WorkspaceCounts::for($team, $brand, $market),
            'usage' => Gate::forUser($user)->allows('viewAny', UsageEvent::class) ? self::usage($team) : null,
            'role' => $role === null ? null : __("roles.{$role->value}.name"),
            'can' => [
                'manage_prompts' => $can(TenantPermission::ManagePrompts),
                'manage_competitors' => $can(TenantPermission::ManageCompetitors),
                'manage_brands' => $can(TenantPermission::ManageBrands),
                'manage_markets' => $can(TenantPermission::ManageMarkets),
                'manage_alerts' => $can(TenantPermission::ManageAlerts),
                'manage_reports' => $can(TenantPermission::ManageReports),
            ],
        ];
    }

    /** «Norge», «Sverige»: the location label when the market has one, otherwise the country in the app locale. */
    public static function marketLabel(Market $market): string
    {
        if (is_string($market->location_label) && $market->location_label !== '') {
            return $market->location_label;
        }
        $country = strtoupper((string) $market->country);
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$country, app()->getLocale()) : '';

        return is_string($name) && $name !== '' && $name !== $country ? $name : $country;
    }

    /** The brand of the route, already resolved in the tenant by route model binding. */
    private static function brand(Request $request): ?Brand
    {
        $brand = $request->route('brand');
        if ($brand instanceof Brand) {
            return $brand;
        }
        if (is_numeric($brand)) {
            return Brand::query()->find((int) $brand);
        }

        return null;
    }

    /**
     * @return array{month: string, calls: int, quota: int|null, cost_usd: string, percent: float|null}
     */
    private static function usage(Team $team): array
    {
        $now = CarbonImmutable::now('UTC');
        $snapshot = app(MonthlyUsage::class)->snapshot($team, $now);
        $quota = $snapshot->quota_calls;

        return [
            'month' => $now->format('Y-m'),
            'calls' => $snapshot->calls,
            'quota' => $quota,
            'cost_usd' => (string) $snapshot->cost_usd,
            'percent' => $quota !== null && $quota > 0 ? round($snapshot->calls / $quota, 4) : null,
        ];
    }
}
