<?php

namespace App\Jobs;

use App\Detection\EntityName;
use App\Models\EntityDecision;
use App\Models\Mention;
use App\Models\Team;
use App\Work\WorkKind;
use App\Work\WorkScope;
use App\Work\WorkSignals;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Moves old mentions of a decided name to the brand or competitor it became (docs/ux-arbeidsflate.md 6.3, ADR
 * 0043), or back to unknown when the decision is undone. Only mentions whose surface name has the decision's key,
 * in answers of the brand's markets collected in the last ekko.entities.reclassify_days days, are touched. Older
 * periods keep the name as unknown. Every tenant-local day with a changed answer is aggregated again through a
 * work signal, so the figures follow.
 *
 * A forward run needs its decision. An undo that came first has deleted it, and then nothing is moved.
 */
class ReclassifyEntityMentions extends TenantJob
{
    private const CHUNK = 1000;

    public function __construct(
        int $tenantId,
        public readonly int $brandId,
        public readonly string $key,
        public readonly string $targetType,
        public readonly int $targetId,
        public readonly ?int $decisionId = null,
        public readonly bool $revert = false,
    ) {
        parent::__construct($tenantId);
    }

    public function handle(WorkSignals $signals): void
    {
        $decision = null;
        if (! $this->revert) {
            // The lock orders this run and an undo of the same decision.
            $decision = EntityDecision::query()->whereKey($this->decisionId)->lockForUpdate()->first();
            if ($decision === null) {
                return;
            }
        }

        $since = CarbonImmutable::now('UTC')->subDays((int) config('ekko.entities.reclassify_days'));
        $from = fn (Builder $query): Builder => $this->revert
            ? $query->where('mentions.entity_type', $this->targetType)->where('mentions.entity_id', $this->targetId)
            : $query->where('mentions.entity_type', 'unknown');

        $surfaces = $from($this->mentions($since))->toBase()->distinct()->pluck('mentions.surface_name')
            ->map(fn ($name): string => (string) $name)
            ->filter(fn (string $name): bool => EntityName::key($name) === $this->key)
            ->values()->all();

        $moved = 0;
        $dates = [];
        if ($surfaces !== []) {
            $timezone = $this->timezone();
            $rows = $from($this->mentions($since))->whereIn('mentions.surface_name', $surfaces)
                ->toBase()->get(['mentions.id', 'responses.collected_at']);
            foreach ($rows->chunk(self::CHUNK) as $chunk) {
                $moved += Mention::query()->whereIn('id', $chunk->pluck('id')->all())->update($this->revert
                    ? ['entity_type' => 'unknown', 'entity_id' => null]
                    : ['entity_type' => $this->targetType, 'entity_id' => $this->targetId]);
            }
            foreach ($rows as $row) {
                $dates[WorkScope::localDate(CarbonImmutable::parse((string) $row->collected_at), $timezone)] = true;
            }
        }

        foreach (array_keys($dates) as $date) {
            $signals->signal(WorkKind::Aggregation, (string) $date);
        }

        $decision?->forceFill(['reclassified_mentions' => $moved])->save();
    }

    /** @return Builder<Mention> */
    private function mentions(CarbonImmutable $since): Builder
    {
        return Mention::query()
            ->join('responses', 'responses.id', '=', 'mentions.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('markets.brand_id', $this->brandId)
            ->where('responses.collected_at', '>=', $since);
    }

    /** The same reading as DailyAggregator: an unknown or empty timezone counts as UTC. */
    private function timezone(): string
    {
        $name = Team::query()->whereKey($this->tenantId)->value('timezone');
        try {
            return (new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC'))->getName();
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
