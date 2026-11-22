<?php

namespace App\Workspace;

use App\Inbox\InboxQuery;
use App\Models\Action;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\BrandFact;
use App\Models\Claim;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\EntityDecision;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Team;
use App\Prompts\EntityQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;

final readonly class WorkspaceCounts
{
    public const TTL_SECONDS = 60;

    /** @var list<class-string<Model>> */
    public const SOURCES = [
        Alert::class, AlertRule::class, Action::class, Integration::class, EntityDecision::class,
        Competitor::class, CompetitorAlias::class, BrandAlias::class, BrandFact::class, Claim::class, Market::class, Brand::class,
    ];

    public function __construct(private InboxQuery $inbox, private EntityQueue $queue) {}

    /** @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int} */
    public static function for(Team $team, ?Brand $brand, ?Market $market = null): array
    {
        return app(self::class)->counts($team, $brand, $market);
    }

    /** @return array{inbox: int, actions_new: int, entities_pending: int, integrations_failing: int} */
    public function counts(Team $team, ?Brand $brand, ?Market $market = null): array
    {
        if ($team->id !== current_tenant_id() || ($brand !== null && $brand->tenant_id !== $team->id)
            || ($market !== null && ($market->tenant_id !== $team->id || ($brand !== null && $market->brand_id !== $brand->id)))) {
            throw new LogicException('Workspace counts are only calculated inside the current tenant.');
        }
        $brand ??= $market?->brand;
        $version = Cache::get(self::versionKey($team->id), 'initial');
        $key = sprintf('workspace.counts.%d.%d.%d.%s', $team->id, $brand->id ?? 0, $market->id ?? 0, $version);

        return Cache::remember($key, self::TTL_SECONDS, function () use ($team, $brand, $market) {
            $counts = $this->inbox->counts($team, $brand, $market);

            return [
                'inbox' => $counts['all'],
                'actions_new' => $brand ? $counts['action'] : 0,
                'entities_pending' => $brand ? count($this->queue->pending($brand, $market ? [$market->id] : [])) : 0,
                'integrations_failing' => $brand ? $counts['integration'] : 0,
            ];
        });
    }

    public static function forget(int $tenantId): void
    {
        Cache::put(self::versionKey($tenantId), (string) Str::uuid(), self::TTL_SECONDS * 2);
    }

    private static function versionKey(int $tenantId): string
    {
        return "workspace.counts.{$tenantId}.version";
    }
}
