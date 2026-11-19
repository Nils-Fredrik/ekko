<?php

namespace App\Metrics;

use App\Jobs\AggregateTenantDays;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\Mention;
use App\Models\ModelChange;
use App\Models\Response;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Builds agg_daily, agg_source_daily and the model change markers for one tenant and one day
 * (docs/spec.md 6.4, 7.5 and 9, ADR 0012).
 *
 * Runs in the tenant context the caller has set (a {@see AggregateTenantDays} job, which also applies
 * row-level security). It is idempotent: the day is recomputed from the stored responses, mentions and
 * citations, rows are updated in place, and rows that no longer have data are removed. It can be run again for
 * any date, also after late extraction results or after a mention has changed.
 *
 * The day is the tenant's local day (teams.timezone): a response belongs to the day its collected_at falls in
 * when read in that timezone. Only responses of runs with status extracted count, so a response whose
 * extraction is still pending does not lower the rates and is picked up by the next run.
 */
final class DailyAggregator
{
    private const CHUNK = 500;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  string  $date  The tenant-local day, Y-m-d.
     * @return array{agg_daily: int, agg_source_daily: int, model_changes: int}
     */
    public function aggregate(string $date): array
    {
        $tenantId = $this->context->require();
        $timezone = $this->timezone($tenantId);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($day === null || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a date (Y-m-d): {$date}");
        }
        $start = $day->startOfDay()->utc();
        $end = $day->addDay()->startOfDay()->utc();

        [$daily, $source] = $this->compute($start, $end);

        return [
            'agg_daily' => $this->store(AggDaily::class, $date, $daily, ['brand_id', 'market_id', 'engine_key', 'prompt_id']),
            'agg_source_daily' => $this->store(AggSourceDaily::class, $date, $source, ['brand_id', 'market_id', 'engine_key', 'domain']),
            'model_changes' => $this->storeModelChanges($date, $start, $end),
        ];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    public function compute(CarbonImmutable $start, CarbonImmutable $end, ?CarbonImmutable $runCreatedBefore = null): array
    {
        // competitor id => brand id: a response only counts the competitors of its own brand.
        $competitorBrand = Competitor::query()->pluck('brand_id', 'id')->all();

        $daily = [];
        $source = [];

        Response::query()
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('runs.status', 'extracted')
            ->where('responses.collected_at', '>=', $start)
            ->where('responses.collected_at', '<', $end)
            ->when($runCreatedBefore !== null, fn ($query) => $query->where('runs.created_at', '<', $runCreatedBefore))
            ->select(['responses.id', 'responses.engine_key', 'responses.answer_type', 'runs.prompt_id', 'prompts.market_id', 'markets.brand_id'])
            ->chunkById(self::CHUNK, function (Collection $chunk) use (&$daily, &$source, $competitorBrand): void {
                $ids = $chunk->pluck('id')->all();
                $mentions = Mention::query()
                    ->whereIn('response_id', $ids)
                    ->whereIn('entity_type', ['brand', 'competitor'])
                    ->get(['response_id', 'entity_type', 'entity_id', 'list_position', 'is_recommended', 'sentiment'])
                    ->groupBy('response_id');
                $citations = Citation::query()
                    ->whereIn('response_id', $ids)
                    ->get(['response_id', 'domain', 'ownership', 'source_type'])
                    ->groupBy('response_id');

                foreach ($chunk as $response) {
                    $this->add($daily, $source, $response, $mentions->get($response->id, collect()), $citations->get($response->id, collect()), $competitorBrand);
                }
            }, 'responses.id', 'id');

        return [$daily, $source];
    }

    /**
     * @param  array<string, array<string, mixed>>  $daily
     * @param  array<string, array<string, mixed>>  $source
     * @param  Collection<int, Mention>  $mentions
     * @param  Collection<int, Citation>  $citations
     * @param  array<int, int>  $competitorBrand
     */
    private function add(array &$daily, array &$source, Response $response, Collection $mentions, Collection $citations, array $competitorBrand): void
    {
        $brandId = (int) $response->getAttribute('brand_id');
        $marketId = (int) $response->getAttribute('market_id');
        $promptId = (int) $response->getAttribute('prompt_id');

        $brandMentions = $mentions->filter(fn (Mention $m) => $m->entity_type === 'brand' && (int) $m->entity_id === $brandId);
        $competitorIds = $mentions
            ->filter(fn (Mention $m) => $m->entity_type === 'competitor' && ($competitorBrand[(int) $m->entity_id] ?? null) === $brandId)
            ->map(fn (Mention $m) => (int) $m->entity_id)
            ->unique();

        $mentioned = $brandMentions->isNotEmpty();
        // The first list position the brand has in the answer (ADR 0010: the deterministic position).
        $position = $brandMentions->pluck('list_position')->filter(fn ($p) => $p !== null)->min();
        $isList = $response->answer_type === 'list' || $mentions->contains(fn (Mention $m) => $m->list_position !== null);
        $competitorOnly = ! $mentioned && $competitorIds->isNotEmpty();

        $key = "{$brandId}|{$marketId}|{$response->engine_key}|{$promptId}";
        $row = $daily[$key] ?? [
            'brand_id' => $brandId, 'market_id' => $marketId, 'engine_key' => $response->engine_key, 'prompt_id' => $promptId,
            'responses' => 0, 'brand_mentioned' => 0, 'brand_recommended' => 0, 'sum_position' => 0, 'count_position' => 0,
            'list_responses' => 0, 'first_place' => 0, 'sentiment_milli' => 0, 'count_sentiment' => 0, 'own_cited' => 0,
            'competitor_only_responses' => 0, 'competitor_mentions' => [],
            'sentiment_distribution' => [
                'own' => ['negative' => 0, 'neutral' => 0, 'positive' => 0],
                'competitors' => [],
            ],
        ];

        $row['responses']++;
        $row['brand_mentioned'] += $mentioned ? 1 : 0;
        $row['brand_recommended'] += $brandMentions->contains(fn (Mention $m) => $m->is_recommended) ? 1 : 0;
        if ($position !== null) {
            $row['sum_position'] += (int) $position;
            $row['count_position']++;
        }
        if ($isList) {
            $row['list_responses']++;
            $row['first_place'] += $position === 1 ? 1 : 0;
        }
        // Sentiment has three decimals, so thousandths add up exactly.
        foreach ($brandMentions as $mention) {
            if ($mention->sentiment !== null) {
                $row['sentiment_milli'] += (int) round(((float) $mention->sentiment) * 1000);
                $row['count_sentiment']++;
                $sentiment = (float) $mention->sentiment;
                $bucket = $sentiment < 0 ? 'negative' : ($sentiment > 0 ? 'positive' : 'neutral');
                $row['sentiment_distribution']['own'][$bucket]++;
            }
        }
        // Only competitors belonging to this brand are included; unknown
        // mentions and known entities from another brand do not contribute.
        foreach ($mentions as $mention) {
            $id = (int) $mention->entity_id;
            if ($mention->entity_type !== 'competitor' || ($competitorBrand[$id] ?? null) !== $brandId || $mention->sentiment === null) {
                continue;
            }
            $sentiment = (float) $mention->sentiment;
            $bucket = $sentiment < 0 ? 'negative' : ($sentiment > 0 ? 'positive' : 'neutral');
            $row['sentiment_distribution']['competitors'][$id] ??= ['negative' => 0, 'neutral' => 0, 'positive' => 0];
            $row['sentiment_distribution']['competitors'][$id][$bucket]++;
        }
        $row['own_cited'] += $citations->contains(fn (Citation $c) => $c->ownership === 'own') ? 1 : 0;
        $row['competitor_only_responses'] += $competitorOnly ? 1 : 0;
        foreach ($competitorIds as $competitorId) {
            $row['competitor_mentions'][$competitorId] = ($row['competitor_mentions'][$competitorId] ?? 0) + 1;
        }
        $daily[$key] = $row;

        foreach ($citations->groupBy(fn (Citation $c) => $c->domain) as $domain => $domainCitations) {
            $sourceKey = "{$brandId}|{$marketId}|{$response->engine_key}|{$domain}";
            $sourceRow = $source[$sourceKey] ?? [
                'brand_id' => $brandId, 'market_id' => $marketId, 'engine_key' => $response->engine_key, 'domain' => (string) $domain,
                'citations' => 0, 'responses_with_domain' => 0, 'gap_responses' => 0,
                'citation_distribution' => [],
            ];
            $sourceRow['citations'] += $domainCitations->count();
            foreach ($domainCitations as $citation) {
                // Keep unknown source types separate from the explicit 'other'
                // category. A domain may have several types or owners.
                $ownership = (string) $citation->ownership;
                $type = $citation->source_type ?? 'unknown';
                $sourceRow['citation_distribution'][$ownership][$type] = ($sourceRow['citation_distribution'][$ownership][$type] ?? 0) + 1;
            }
            $sourceRow['responses_with_domain']++;
            // A gap: the response names competitors but not the brand, and the domain is not the brand's own.
            $sourceRow['gap_responses'] += $competitorOnly && ! $domainCitations->contains(fn (Citation $c) => $c->ownership === 'own') ? 1 : 0;
            $source[$sourceKey] = $sourceRow;
        }
    }

    /**
     * Writes the computed rows for the day and removes rows of the day that are no longer computed.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $dimensions
     */
    private function store(string $model, string $date, array $rows, array $dimensions): int
    {
        $now = now();
        $records = [];
        foreach ($rows as $row) {
            if (array_key_exists('sentiment_milli', $row)) {
                $row['sum_sentiment'] = number_format($row['sentiment_milli'] / 1000, 3, '.', '');
                unset($row['sentiment_milli']);
            }
            if (array_key_exists('competitor_mentions', $row)) {
                ksort($row['competitor_mentions']);
                $row['competitor_mentions'] = json_encode((object) $row['competitor_mentions'], JSON_THROW_ON_ERROR);
            }
            if (array_key_exists('sentiment_distribution', $row)) {
                ksort($row['sentiment_distribution']['competitors']);
                $row['sentiment_distribution']['competitors'] = (object) $row['sentiment_distribution']['competitors'];
                $row['sentiment_distribution'] = json_encode($row['sentiment_distribution'], JSON_THROW_ON_ERROR);
            }
            if (array_key_exists('citation_distribution', $row)) {
                ksort($row['citation_distribution']);
                foreach ($row['citation_distribution'] as &$types) {
                    ksort($types);
                }
                unset($types);
                $row['citation_distribution'] = json_encode($row['citation_distribution'], JSON_THROW_ON_ERROR);
            }
            $records[] = [...$row, 'date' => $date, 'created_at' => $now, 'updated_at' => $now];
        }

        $uniqueBy = ['tenant_id', ...$dimensions, 'date'];
        $update = array_values(array_diff(array_keys($records[0] ?? []), [...$dimensions, 'date', 'created_at']));
        foreach (array_chunk($records, 500) as $chunk) {
            $model::query()->upsert($chunk, $uniqueBy, $update);
        }

        $keep = array_flip(array_keys($rows));
        $stale = $model::query()->where('date', $date)->get(['id', ...$dimensions])
            ->filter(fn (Model $existing) => ! isset($keep[implode('|', array_map(fn (string $d) => (string) $existing->getAttribute($d), $dimensions))]))
            ->pluck('id')->all();
        foreach (array_chunk($stale, 500) as $ids) {
            $model::query()->whereIn('id', $ids)->delete();
        }

        return count($rows);
    }

    /**
     * Model change markers (docs/spec.md 7.5): the first time on the day an engine answered with another model id
     * than its previous answer. At most one marker per engine and day. Answers without a model id are ignored.
     */
    private function storeModelChanges(string $date, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $answers = Response::query()
            ->whereNotNull('model_id_reported')
            ->where('collected_at', '>=', $start)
            ->where('collected_at', '<', $end)
            ->orderBy('collected_at')->orderBy('id')
            ->get(['engine_key', 'model_id_reported', 'collected_at']);

        $before = $answers->isEmpty() ? [] : Response::query()
            ->whereNotNull('model_id_reported')
            ->where('collected_at', '<', $start)
            ->whereIn('engine_key', $answers->pluck('engine_key')->unique()->all())
            ->selectRaw('distinct on (engine_key) engine_key, model_id_reported')
            ->orderBy('engine_key')->orderByDesc('collected_at')->orderByDesc('id')
            ->pluck('model_id_reported', 'engine_key')->all();

        $markers = [];
        $last = $before;
        foreach ($answers as $answer) {
            $engine = (string) $answer->engine_key;
            $model = (string) $answer->model_id_reported;
            if (isset($last[$engine]) && $last[$engine] !== $model && ! isset($markers[$engine])) {
                $markers[$engine] = [
                    'engine_key' => $engine, 'changed_on' => $date, 'from_model' => $last[$engine], 'to_model' => $model,
                    'changed_at' => $answer->collected_at, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $last[$engine] = $model;
        }

        ModelChange::query()->where('changed_on', $date)->whereNotIn('engine_key', array_keys($markers))->delete();
        if ($markers !== []) {
            ModelChange::query()->upsert(array_values($markers), ['tenant_id', 'engine_key', 'changed_on'], ['from_model', 'to_model', 'changed_at', 'updated_at']);
        }

        return count($markers);
    }

    private function timezone(int $tenantId): DateTimeZone
    {
        $name = Team::query()->whereKey($tenantId)->value('timezone');

        try {
            return new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
