<?php

namespace App\Prompts;

use App\Detection\EntityName;
use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\EntityDecision;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Unknown brands waiting for a decision (docs/ux-arbeidsflate.md 6.3, ADR 0043). Built on
 * MetricsService::unknownBrands, which groups spellings by EntityName::key. Names the brand already knows (its
 * name and aliases, its competitors and their aliases, compared through the same key) and names with a decision
 * are left out. The new_competitor alert, the new_competitor action source and the competitor pages all read
 * unknown brands through this class, so a decided name is never alerted, suggested or queued again.
 *
 * Suggestions are rules, not a model: a known platform is another actor, a name that is or starts with the key of
 * the brand or a competitor is an alias of it, and a generic word never written with a capital letter is ignored.
 */
final readonly class EntityQueue
{
    public function __construct(private MetricsService $metrics) {}

    /**
     * Names in at least ekko.entities.min_answers answers of the brand's markets over the last $days tenant-local
     * days (today included), most answers first.
     *
     * @param  list<int>  $marketIds  the brand's markets when empty
     * @return list<QueueItem>
     */
    public function pending(Brand $brand, array $marketIds = [], ?int $days = null): array
    {
        $ids = $this->marketIds($brand, $marketIds);
        if ($ids === []) {
            return [];
        }

        [$from, $to] = self::window($brand, $days ?? (int) config('ekko.entities.queue_days'));
        $minimum = (int) config('ekko.entities.min_answers');
        $targets = self::targets($brand);
        $platforms = self::keysOf((array) config('ekko.entities.known_platforms'));

        $items = [];
        foreach ($this->unknown($brand, new MetricsFilter($brand->id, $from, $to, marketIds: $ids)) as $row) {
            if ($row['answers'] < $minimum) {
                continue;
            }
            $items[] = new QueueItem(
                key: $row['key'],
                name: $row['name'],
                variants: $row['variants'],
                answers: $row['answers'],
                share: $row['metric']->value,
                n: $row['metric']->n,
                markets: $row['markets'],
                engines: $row['engines'],
                lastSeen: $row['last_seen'],
                suggestion: self::suggest($row['key'], $row['variants'], $targets, $platforms),
            );
        }

        return $items;
    }

    /**
     * The unknown brands of the filter (MetricsService::unknownBrands) without known names and names with a
     * decision. The alert and the action source apply their own thresholds to these.
     *
     * @return list<array{key: string, name: string, variants: list<string>, answers: int, metric: Metric, markets: list<int>, engines: list<string>, last_seen: string}>
     */
    public function unknown(Brand $brand, MetricsFilter $filter): array
    {
        $skip = self::knownKeys($brand) + self::decidedKeys($brand);

        return array_values(array_filter(
            $this->metrics->unknownBrands($filter),
            fn (array $row): bool => ! isset($skip[$row['key']]),
        ));
    }

    /**
     * Every spelling of the brand's unknown names over the reclassification window, any market and answer count,
     * keyed by name key; decisions take their variants from here. Names already known or decided are included.
     *
     * @param  list<string>  $keys
     * @return array<string, array{name: string, variants: list<string>}>
     */
    public function lookup(Brand $brand, array $keys): array
    {
        $ids = $this->marketIds($brand, []);
        if ($ids === [] || $keys === []) {
            return [];
        }
        [$from, $to] = self::window($brand, (int) config('ekko.entities.reclassify_days'));
        $wanted = array_fill_keys($keys, true);

        $found = [];
        foreach ($this->metrics->unknownBrands(new MetricsFilter($brand->id, $from, $to, marketIds: $ids)) as $row) {
            if (isset($wanted[$row['key']])) {
                $found[$row['key']] = ['name' => $row['name'], 'variants' => $row['variants']];
            }
        }

        return $found;
    }

    /**
     * The keys of the names the brand knows: its name and aliases, its competitors (not archived) and their aliases.
     *
     * @return array<string, true>
     */
    public static function knownKeys(Brand $brand): array
    {
        $names = [$brand->name];
        foreach (BrandAlias::query()->where('brand_id', $brand->id)->where('match_mode', '!=', 'regex')->pluck('alias') as $alias) {
            $names[] = (string) $alias;
        }
        $competitors = Competitor::query()->where('brand_id', $brand->id)->get(['id', 'name']);
        foreach ($competitors as $competitor) {
            $names[] = (string) $competitor->name;
        }
        if ($competitors->isNotEmpty()) {
            foreach (CompetitorAlias::query()->whereIn('competitor_id', $competitors->modelKeys())->where('match_mode', '!=', 'regex')->pluck('alias') as $alias) {
                $names[] = (string) $alias;
            }
        }

        return self::keysOf($names);
    }

    /** @return array<string, true> */
    public static function decidedKeys(Brand $brand): array
    {
        $keys = [];
        foreach (EntityDecision::query()->where('brand_id', $brand->id)->pluck('name_key') as $key) {
            $keys[(string) $key] = true;
        }

        return $keys;
    }

    /**
     * The first and last tenant-local day of a window of $days days ending today.
     *
     * @return array{0: string, 1: string}
     */
    public static function window(Brand $brand, int $days): array
    {
        $timezone = Team::query()->whereKey($brand->getAttribute('tenant_id'))->value('timezone');
        try {
            $today = CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')->startOfDay();
        } catch (\Throwable) {
            $today = CarbonImmutable::now('UTC')->startOfDay();
        }

        return [$today->subDays(max(1, $days) - 1)->toDateString(), $today->toDateString()];
    }

    /**
     * @param  list<string>  $variants
     * @param  list<array{type: string, id: int, name: string, key: string}>  $targets
     * @param  array<string, true>  $platforms
     * @return array{decision: string, target_type?: string, target_id?: int, target_name?: string}|null
     */
    public static function suggest(string $key, array $variants, array $targets, array $platforms): ?array
    {
        if (isset($platforms[$key])) {
            return ['decision' => EntityDecision::OTHER];
        }

        // The longest matching key wins, so «Nordlys Norge» goes to «Nordlys Norge» before «Nordlys». A prefix must
        // end at a word, so «Nordlyset» is not an alias of «Nord».
        $best = null;
        foreach ($targets as $target) {
            if ($target['key'] === '' || ($key !== $target['key'] && ! str_starts_with($key, $target['key'].' '))) {
                continue;
            }
            if ($best === null || mb_strlen($target['key']) > mb_strlen($best['key'])) {
                $best = $target;
            }
        }
        if ($best !== null) {
            return ['decision' => EntityDecision::ALIAS, 'target_type' => $best['type'], 'target_id' => $best['id'], 'target_name' => $best['name']];
        }

        if (self::generic($key) && ! self::capitalized($variants)) {
            return ['decision' => EntityDecision::IGNORED];
        }

        return null;
    }

    /**
     * The brand and its active competitors in color order, the possible targets of an alias.
     *
     * @return list<array{type: string, id: int, name: string, key: string}>
     */
    public static function targets(Brand $brand): array
    {
        $targets = [['type' => 'brand', 'id' => (int) $brand->id, 'name' => (string) $brand->name, 'key' => EntityName::key((string) $brand->name)]];
        foreach (Competitor::query()->where('brand_id', $brand->id)->where('is_active', true)->inColorOrder()->get(['id', 'name']) as $competitor) {
            $targets[] = ['type' => 'competitor', 'id' => (int) $competitor->id, 'name' => (string) $competitor->name, 'key' => EntityName::key((string) $competitor->name)];
        }

        return $targets;
    }

    /**
     * @param  iterable<mixed>  $names
     * @return array<string, true>
     */
    private static function keysOf(iterable $names): array
    {
        $keys = [];
        foreach ($names as $name) {
            $key = EntityName::key((string) $name);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** A word of the key is, or ends with, a generic word («byrå», «reklamebyrå»). */
    private static function generic(string $key): bool
    {
        $words = array_keys(self::keysOf((array) config('ekko.entities.generic_words')));
        foreach (explode(' ', $key) as $part) {
            foreach ($words as $word) {
                if ($part === $word || str_ends_with($part, (string) $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<string>  $variants */
    private static function capitalized(array $variants): bool
    {
        foreach ($variants as $variant) {
            if (preg_match('/^\P{L}*\p{Lu}/u', $variant) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $marketIds
     * @return list<int>
     */
    private function marketIds(Brand $brand, array $marketIds): array
    {
        $own = $brand->markets()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $marketIds === [] ? $own : array_values(array_intersect($own, array_map('intval', $marketIds)));
    }
}
