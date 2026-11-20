<?php

namespace App\Api;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ActionQuery
{
    /** @param array<string, mixed> $input
     * @return Builder<Action>
     */
    public static function forBrand(Brand $brand, array $input): Builder
    {
        Gate::authorize('view', $brand);
        $filters = Validator::make($input, [
            'status' => ['nullable', Rule::enum(ActionStatus::class)],
            'type' => ['nullable', Rule::in([...array_column(ActionType::cases(), 'value'), 'own'])],
            'market' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        if (isset($filters['market'])) {
            Market::query()->where('brand_id', $brand->id)->findOrFail($filters['market']);
        }

        return Action::query()->where('brand_id', $brand->id)->with('prompts:id')
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['type'] ?? null) === 'own', fn ($q) => $q->whereNull('type'))
            ->when(isset($filters['type']) && $filters['type'] !== 'own', fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['market']), fn ($q) => $q->where('market_id', $filters['market']))->orderBy('id');
    }
}
