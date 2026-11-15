<?php

namespace App\Prompts;

use App\Dashboard\DashboardPeriod;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\Mention;
use Carbon\CarbonImmutable;

/**
 * Unknown brands that recur in the period (docs/spec.md 8.2). Two answers is enough to suggest one.
 * Names already used by the brand or a competitor are left out.
 */
final class UnknownBrands
{
    /**
     * @param  list<int>  $marketIds
     * @return list<array{name: string, responses: int}>
     */
    public static function suggestions(Brand $brand, DashboardPeriod $period, array $marketIds = []): array
    {
        $from = CarbonImmutable::parse($period->from.' 00:00:00', $period->timezone)->utc();
        $to = CarbonImmutable::parse($period->to.' 23:59:59', $period->timezone)->utc();
        $known = self::knownNames($brand);

        $mentions = Mention::query()
            ->where('entity_type', 'unknown')
            ->whereHas('response', function ($query) use ($brand, $from, $to, $marketIds): void {
                $query->whereBetween('collected_at', [$from, $to])
                    ->whereHas('run.prompt.market', function ($market) use ($brand, $marketIds): void {
                        $market->where('brand_id', $brand->id);
                        if ($marketIds !== []) {
                            $market->whereIn('id', $marketIds);
                        }
                    });
            })
            ->get(['surface_name', 'response_id']);

        $grouped = [];
        foreach ($mentions as $mention) {
            $name = trim((string) $mention->surface_name);
            $key = mb_strtolower($name);
            if ($name === '' || isset($known[$key])) {
                continue;
            }

            if (! isset($grouped[$key])) {
                $grouped[$key] = ['name' => $name, 'responses' => []];
            }
            $grouped[$key]['responses'][(int) $mention->response_id] = true;
        }

        $suggestions = [];
        foreach ($grouped as $group) {
            $count = count($group['responses']);
            if ($count < 2) {
                continue;
            }

            $suggestions[] = ['name' => $group['name'], 'responses' => $count];
        }

        usort($suggestions, fn (array $a, array $b): int => $b['responses'] <=> $a['responses'] ?: strcmp($a['name'], $b['name']));

        return $suggestions;
    }

    /**
     * @return array<string, true>
     */
    public static function knownNames(Brand $brand): array
    {
        $names = [mb_strtolower($brand->name) => true];

        foreach (BrandAlias::query()->where('brand_id', $brand->id)->pluck('alias') as $alias) {
            $names[mb_strtolower((string) $alias)] = true;
        }

        $competitors = Competitor::query()->where('brand_id', $brand->id)->get(['id', 'name']);
        foreach ($competitors as $competitor) {
            $names[mb_strtolower($competitor->name)] = true;
        }

        $ids = $competitors->pluck('id');
        if ($ids->isNotEmpty()) {
            foreach (CompetitorAlias::query()->whereIn('competitor_id', $ids)->pluck('alias') as $alias) {
                $names[mb_strtolower((string) $alias)] = true;
            }
        }

        return $names;
    }
}
