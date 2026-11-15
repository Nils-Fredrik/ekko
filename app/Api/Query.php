<?php

namespace App\Api;

use App\Metrics\MetricsFilter;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Query
{
    public static function pageSize(Request $request): int
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return (int) ($data['per_page'] ?? 50);
    }

    public static function timezone(): string
    {
        return Team::query()->findOrFail(current_tenant_id())->timezone;
    }

    public static function filter(Request $request, Brand $brand): MetricsFilter
    {
        $today = CarbonImmutable::now(self::timezone());
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'market' => ['sometimes', 'integer', 'min:1'],
            'engine' => ['sometimes', 'string', 'max:100', Rule::exists('engines', 'key')],
        ]);
        $to = $data['to'] ?? $today->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to)->subDays(27)->toDateString();
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365) {
            throw ValidationException::withMessages(['from' => __('api.period')]);
        }
        if (isset($data['market'])) {
            Market::query()->where('brand_id', $brand->id)->findOrFail($data['market']);
        }

        return new MetricsFilter($brand->id, $from, $to,
            marketIds: isset($data['market']) ? [(int) $data['market']] : [],
            engineKeys: isset($data['engine']) ? [$data['engine']] : [],
        );
    }
}
