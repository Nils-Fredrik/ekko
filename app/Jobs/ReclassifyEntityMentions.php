<?php

namespace App\Jobs;

use App\Detection\EntityName;
use App\Models\EntityDecision;
use App\Prompts\EntityMentions;
use Carbon\CarbonImmutable;

/**
 * Moves old mentions of a decided name to the brand or competitor it became (docs/ux-arbeidsflate.md 6.3, ADR
 * 0043). Only unknown mentions whose surface name has the decision's key, in answers of the brand's markets
 * collected in the last ekko.entities.reclassify_days days, are moved. Older periods keep the name as unknown.
 * Every tenant-local day with a changed answer is aggregated again through a work signal, and the moved mentions
 * are stored on the decision, so an undo moves back exactly these.
 *
 * The decision is locked while the job runs. An undo that came first has deleted it, and then nothing is moved.
 */
class ReclassifyEntityMentions extends TenantJob
{
    public function __construct(int $tenantId, public readonly int $decisionId)
    {
        parent::__construct($tenantId);
    }

    public function handle(EntityMentions $mentions): void
    {
        $decision = EntityDecision::query()->whereKey($this->decisionId)->lockForUpdate()->first();
        if ($decision === null || $decision->target_type === null || $decision->target_id === null) {
            return;
        }

        $since = CarbonImmutable::now('UTC')->subDays((int) config('ekko.entities.reclassify_days'));
        $unknown = $mentions->inBrandMarkets($decision->brand_id, $since)->where('mentions.entity_type', 'unknown');
        $surfaces = (clone $unknown)->toBase()->distinct()->pluck('mentions.surface_name')
            ->map(fn ($name): string => (string) $name)
            ->filter(fn (string $name): bool => EntityName::key($name) === $decision->name_key)
            ->values()->all();

        $ids = $surfaces === [] ? [] : $unknown->whereIn('mentions.surface_name', $surfaces)->pluck('mentions.id')
            ->map(fn ($id): int => (int) $id)->all();
        $moved = $mentions->move($ids, ['entity_type' => $decision->target_type, 'entity_id' => $decision->target_id], from: ['unknown', null]);

        $decision->forceFill([
            'reclassified_mentions' => count($moved),
            'mention_ids' => array_values(array_unique([...$decision->mention_ids, ...$moved])),
        ])->save();
    }
}
