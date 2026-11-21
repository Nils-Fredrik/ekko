<?php

namespace App\Prompts;

use App\Models\Mention;
use App\Models\Team;
use App\Tenancy\TenantContext;
use App\Work\WorkKind;
use App\Work\WorkScope;
use App\Work\WorkSignals;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Moves mentions between unknown and a brand or competitor for decisions on unknown brands (ADR 0043), and
 * registers aggregation for every tenant-local day with a changed answer. Runs in the caller's transaction.
 */
final readonly class EntityMentions
{
    private const CHUNK = 1000;

    public function __construct(private WorkSignals $signals, private TenantContext $context) {}

    /**
     * Mentions in answers of the brand's markets collected since $since.
     *
     * @return Builder<Mention>
     */
    public function inBrandMarkets(int $brandId, CarbonImmutable $since): Builder
    {
        return Mention::query()
            ->join('responses', 'responses.id', '=', 'mentions.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('markets.brand_id', $brandId)
            ->where('responses.collected_at', '>=', $since);
    }

    /**
     * Moves the mentions among $ids that are still classified as $from to $to. Mentions changed since by someone
     * else are left alone.
     *
     * @param  list<int>  $ids
     * @param  array{entity_type: string, entity_id: int|null}  $to
     * @param  array{0: string, 1: int|null}  $from
     * @return list<int> the moved mentions
     */
    public function move(array $ids, array $to, array $from): array
    {
        $moved = [];
        $dates = [];
        $timezone = $this->timezone();
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $rows = Mention::query()
                ->join('responses', 'responses.id', '=', 'mentions.response_id')
                ->whereIn('mentions.id', $chunk)
                ->where('mentions.entity_type', $from[0])
                ->when($from[1] === null, fn (Builder $q) => $q->whereNull('mentions.entity_id'), fn (Builder $q) => $q->where('mentions.entity_id', $from[1]))
                ->toBase()->get(['mentions.id', 'responses.collected_at']);
            if ($rows->isEmpty()) {
                continue;
            }
            $rowIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
            Mention::query()->whereIn('id', $rowIds)->update($to);
            array_push($moved, ...$rowIds);
            foreach ($rows as $row) {
                $dates[WorkScope::localDate(CarbonImmutable::parse((string) $row->collected_at), $timezone)] = true;
            }
        }

        foreach (array_keys($dates) as $date) {
            $this->signals->signal(WorkKind::Aggregation, (string) $date);
        }

        return $moved;
    }

    /** The same reading as DailyAggregator: an unknown or empty timezone counts as UTC. */
    private function timezone(): string
    {
        $name = Team::query()->whereKey($this->context->require())->value('timezone');
        try {
            return (new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC'))->getName();
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
