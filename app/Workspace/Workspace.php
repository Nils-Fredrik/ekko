<?php

namespace App\Workspace;

use App\Enums\TenantPermission;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\TenantAccess;
use App\Usage\MonthlyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Locale;

/**
 * The shared `workspace` prop for the shell (docs/ux-arbeidsflate.md 4.1): the tenant's brands, the
 * brand and market in scope, the sidebar counts, this month's usage and what the user may change.
 * The brand comes from the {brand} route parameter and the market from ?market=<id>.
 */
final readonly class Workspace
{
    public function __construct(private TenantAccess $access, private MonthlyUsage $usage) {}

    /**
     * @return array{brands: list<array{id: int, name: string}>, brand: array{id: int, name: string}|null, markets: list<array{id: int, label: string, is_active: bool}>, market_id: int|null, counts: array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}, usage: array{month: string, calls: int, quota: int|null, cost_usd: string, percent: int|null}|null, can: array<string, bool>, role: string|null}|null
     */
    public function payload(Request $request, int $tenantId): ?array
    {
        $user = $request->user();
        $team = Team::query()->find($tenantId);
        if (! $user instanceof User || ! $team instanceof Team) {
            return null;
        }

        $brands = Gate::forUser($user)->allows('viewAny', Brand::class)
            ? Brand::query()->orderBy('name')->get(['id', 'name'])
            : collect();
        $brand = $this->brand($request, $user);
        $markets = $brand === null ? collect() : $brand->markets()->orderBy('id')->get();
        $marketId = $request->query('market');
        $market = is_numeric($marketId) ? $markets->firstWhere('id', (int) $marketId) : null;
        $locale = app()->getLocale();

        return [
            'brands' => $brands->map(fn (Brand $item): array => ['id' => $item->id, 'name' => $item->name])->values()->all(),
            'brand' => $brand === null ? null : ['id' => $brand->id, 'name' => $brand->name],
            'markets' => $markets->map(fn (Market $item): array => [
                'id' => $item->id,
                'label' => self::marketLabel($item, $locale),
                'is_active' => $item->is_active,
            ])->values()->all(),
            'market_id' => $market instanceof Market ? $market->id : null,
            'counts' => WorkspaceCounts::for($team, $brand),
            'usage' => $this->usage($user, $team),
            'can' => $this->can($user, $team),
            'role' => $this->access->roleIn($user, $team)?->nameKey(),
        ];
    }

    /** «Norge», or the location label when the market has one. */
    public static function marketLabel(Market $market, string $locale): string
    {
        if (is_string($market->location_label) && $market->location_label !== '') {
            return $market->location_label;
        }
        $country = strtoupper($market->country);
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$country, $locale) : '';

        return is_string($name) && $name !== '' && $name !== $country ? $name : $country;
    }

    private function brand(Request $request, User $user): ?Brand
    {
        $brand = $request->route('brand');
        if (! $brand instanceof Brand) {
            $brand = is_numeric($brand) ? Brand::query()->find((int) $brand) : null;
        }

        return $brand instanceof Brand && Gate::forUser($user)->allows('view', $brand) ? $brand : null;
    }

    /**
     * @return array{month: string, calls: int, quota: int|null, cost_usd: string, percent: int|null}|null
     */
    private function usage(User $user, Team $team): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', UsageEvent::class)) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        $snapshot = $this->usage->snapshot($team, $now);
        $quota = $snapshot->quota_calls === null ? null : (int) $snapshot->quota_calls;

        return [
            'month' => $now->format('Y-m'),
            'calls' => (int) $snapshot->calls,
            'quota' => $quota,
            'cost_usd' => (string) $snapshot->cost_usd,
            'percent' => $quota === null || $quota <= 0 ? null : (int) round($snapshot->calls / $quota * 100),
        ];
    }

    /** @return array<string, bool> */
    private function can(User $user, Team $team): array
    {
        $can = fn (TenantPermission $permission): bool => $this->access->can($user, $team, $permission);

        return [
            'manage_prompts' => $can(TenantPermission::ManagePrompts),
            'manage_competitors' => $can(TenantPermission::ManageCompetitors),
            'manage_brands' => $can(TenantPermission::ManageBrands),
            'manage_markets' => $can(TenantPermission::ManageMarkets),
            'manage_alerts' => $can(TenantPermission::ManageAlerts),
            'manage_reports' => $can(TenantPermission::ManageReports),
        ];
    }
}
