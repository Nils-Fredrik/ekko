<?php

namespace App\BotLogs;

use App\Models\BotHitDaily;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type HitRow array{date: string, domain: string, bot: string, hits: int, unique_paths: int, top_paths: list<array{path: string, hits: int}>}
 */
final class BotHits
{
    /**
     * Each caller locks the brand row and replaces its complete snapshot in one transaction.
     *
     * @param  list<string>  $dates
     * @param  list<HitRow>  $rows
     */
    public function replace(Brand $brand, array $dates, array $rows, ?string $domain = null): void
    {
        BotHitDaily::query()->where('brand_id', $brand->id)->whereIn('date', $dates)
            ->when($domain !== null, fn (Builder $query) => $query->where('domain', $domain))
            ->delete();

        foreach (array_chunk($rows, 100) as $chunk) {
            BotHitDaily::query()->insert(array_map(fn (array $row): array => [
                ...$row,
                'brand_id' => $brand->id,
                'top_paths' => json_encode($row['top_paths'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }
}
