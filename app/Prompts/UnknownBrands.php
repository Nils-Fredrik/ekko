<?php

namespace App\Prompts;

use App\Dashboard\DashboardPeriod;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;

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
        $ids = $marketIds !== []
            ? array_map(fn (int|string $id): int => (int) $id, $marketIds)
            : $brand->markets()->orderBy('id')->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $known = self::knownNames($brand);
        $rows = app(MetricsService::class)->unknownBrands(new MetricsFilter($brand->id, $period->from, $period->to, marketIds: $ids));

        $suggestions = [];
        foreach ($rows as $row) {
            if (isset($known[$row['key']])) {
                continue;
            }

            // Metric::n is every answer in the markets. The mention count is that times the share.
            $mentioned = (int) round(($row['metric']->value ?? 0) * $row['metric']->n);
            if ($mentioned < 2) {
                continue;
            }

            $suggestions[] = ['name' => $row['name'], 'responses' => $mentioned];
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
