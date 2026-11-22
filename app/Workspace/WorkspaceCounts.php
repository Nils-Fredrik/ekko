<?php

namespace App\Workspace;

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Team;
use App\Prompts\EntityQueue;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * The badge counts in the sidebar (docs/ux-arbeidsflate.md 4.1, U17). They are cached per tenant and brand for
 * 60 seconds. Saving a model behind a count clears the tenant's counts (AppServiceProvider), so a decision in
 * 6.2–6.5 shows at once.
 */
final class WorkspaceCounts
{
    public const TTL_SECONDS = 60;

    private const KEYS = ['inbox', 'actions_new', 'entities_pending', 'integrations_failing'];

    /**
     * @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}
     */
    public static function for(Team $team, ?Brand $brand): array
    {
        $key = sprintf('workspace-counts:%d:%s:%d', $team->id, self::version($team->id), $brand === null ? 0 : $brand->id);
        $cached = Cache::get($key);
        if (is_array($cached) && array_keys($cached) === self::KEYS && array_filter($cached, 'is_int') === $cached) {
            return $cached;
        }
        $counts = app(TenantContext::class)->run($team->id, fn (): array => self::count($brand));
        Cache::put($key, $counts, self::TTL_SECONDS);

        return $counts;
    }

    /** Clears the cached counts of a tenant, for every brand. */
    public static function forget(int $tenantId): void
    {
        Cache::forever(self::versionKey($tenantId), bin2hex(random_bytes(8)));
    }

    /**
     * @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}
     */
    private static function count(?Brand $brand): array
    {
        // The inbox (6.5) is built by U25. Until then it holds the alerts nobody has acknowledged.
        $inbox = Alert::query()->whereNull('acknowledged_at')->count();
        if ($brand === null) {
            return ['inbox' => $inbox, 'actions_new' => 0, 'entities_pending' => 0, 'integrations_failing' => 0];
        }

        return [
            'inbox' => $inbox,
            'actions_new' => Action::query()->where('brand_id', $brand->id)->where('status', ActionStatus::New->value)->count(),
            'entities_pending' => count(app(EntityQueue::class)->pending($brand)),
            'integrations_failing' => Integration::query()->where('brand_id', $brand->id)->where('status', 'error')->count(),
        ];
    }

    private static function version(int $tenantId): string
    {
        $version = Cache::get(self::versionKey($tenantId));

        return is_string($version) ? $version : '0';
    }

    private static function versionKey(int $tenantId): string
    {
        return "workspace-counts-version:{$tenantId}";
    }
}
