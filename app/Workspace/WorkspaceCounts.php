<?php

namespace App\Workspace;

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\EntityDecision;
use App\Models\Integration;
use App\Models\Team;
use App\Prompts\EntityQueue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * The counts in the workspace sidebar (docs/ux-arbeidsflate.md 4.1). Cached per tenant and brand for
 * 60 seconds. A saved decision, alert, action or integration flushes the tenant's counts
 * (AppServiceProvider), so the sidebar follows the change at the next visit.
 */
final readonly class WorkspaceCounts
{
    public const TTL_SECONDS = 60;

    /**
     * Models whose changes move a count: alerts for the inbox, actions, integrations, and decisions,
     * competitors and aliases for the queue of unknown brands.
     *
     * @var list<class-string<Model>>
     */
    public const SOURCES = [
        Alert::class,
        Action::class,
        Integration::class,
        EntityDecision::class,
        Competitor::class,
        CompetitorAlias::class,
        BrandAlias::class,
    ];

    /** Unacknowledged alerts in this window count for the inbox until the inbox (U25) exists. */
    public const INBOX_DAYS = 30;

    public function __construct(private EntityQueue $queue) {}

    /**
     * @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}
     */
    public static function for(Team $team, ?Brand $brand): array
    {
        return app(self::class)->counts($team, $brand);
    }

    /**
     * @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}
     */
    public function counts(Team $team, ?Brand $brand): array
    {
        if ($team->id !== current_tenant_id() || ($brand !== null && (int) $brand->getAttribute('tenant_id') !== $team->id)) {
            throw new LogicException('Workspace counts are only calculated inside the current tenant.');
        }

        /** @var array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int} */
        return Cache::remember(
            self::key($team->id, $brand?->id),
            self::TTL_SECONDS,
            fn (): array => $this->calculate($brand),
        );
    }

    /** Forget every cached count of the tenant, for all brands. */
    public static function flush(int $tenantId): void
    {
        Cache::forever(self::versionKey($tenantId), self::version($tenantId) + 1);
    }

    /**
     * @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int}
     */
    private function calculate(?Brand $brand): array
    {
        $inbox = Alert::query()
            ->whereNull('acknowledged_at')
            ->where('triggered_at', '>=', CarbonImmutable::now()->subDays(self::INBOX_DAYS))
            ->count();

        if ($brand === null) {
            return ['inbox' => $inbox, 'actions_new' => 0, 'entities_pending' => 0, 'integrations_failing' => 0];
        }

        return [
            'inbox' => $inbox,
            'actions_new' => Action::query()->where('brand_id', $brand->id)->where('status', ActionStatus::New->value)->count(),
            'entities_pending' => count($this->queue->pending($brand)),
            'integrations_failing' => Integration::query()->where('brand_id', $brand->id)->where('status', 'error')->count(),
        ];
    }

    private static function key(int $tenantId, ?int $brandId): string
    {
        return sprintf('workspace.counts.%d.%d.v%d', $tenantId, $brandId ?? 0, self::version($tenantId));
    }

    private static function version(int $tenantId): int
    {
        $version = Cache::get(self::versionKey($tenantId), 0);

        return is_int($version) ? $version : 0;
    }

    private static function versionKey(int $tenantId): string
    {
        return "workspace.counts.{$tenantId}.version";
    }
}
