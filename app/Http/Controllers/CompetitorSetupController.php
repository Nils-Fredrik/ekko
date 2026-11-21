<?php

namespace App\Http\Controllers;

use App\Detection\EntityName;
use App\Detection\UrlNormalizer;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use App\Prompts\EntityQueue;
use App\Rules\SafeRegexAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The competitor setup API of the workspace (docs/ux-arbeidsflate.md 6.3 and 5.3): name and active state, the color
 * order, aliases and domains. Every change answers with the competitor as it is now, so the panel can show it.
 */
class CompetitorSetupController extends Controller
{
    private const DOMAIN = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\z/i';

    private const MATCH_MODES = ['exact_word', 'case_insensitive', 'regex'];

    public function update(Request $request, Brand $brand, string $competitor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $record = DB::transaction(function () use ($brand, $competitor, $data): Competitor {
            Brand::query()->whereKey($brand->id)->lockForUpdate()->first();
            $record = $this->competitor($brand, $competitor, lock: true);
            Gate::authorize('update', $record);

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                $key = EntityName::key($name);
                // Another known name with the same key would make two entities of one name.
                $others = EntityQueue::knownKeys($brand);
                unset($others[EntityName::key($record->name)]);
                foreach ($record->aliases()->where('match_mode', '!=', 'regex')->pluck('alias') as $alias) {
                    unset($others[EntityName::key((string) $alias)]);
                }
                if ($name === '' || $key === '' || isset($others[$key])) {
                    throw ValidationException::withMessages(['name' => __('app.library.duplicate')]);
                }
                $record->name = $name;
            }
            if (array_key_exists('is_active', $data)) {
                $record->is_active = (bool) $data['is_active'];
            }
            $record->save();

            return $record;
        });

        return response()->json(['competitor' => $this->payload($brand, $record)]);
    }

    /** The new color order: every active competitor once. Inactive and archived competitors keep their place. */
    public function reorder(Request $request, Brand $brand): JsonResponse
    {
        Gate::authorize('create', Competitor::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        DB::transaction(function () use ($brand, $ids): void {
            Brand::query()->whereKey($brand->id)->lockForUpdate()->first();
            $active = Competitor::query()->where('brand_id', $brand->id)->where('is_active', true)->inColorOrder()->lockForUpdate()->get()->keyBy('id');
            $given = $ids;
            $current = $active->keys()->map(fn ($id): int => (int) $id)->all();
            sort($given);
            sort($current);
            if ($given !== $current) {
                throw ValidationException::withMessages(['ids' => __('entities.errors.reorder')]);
            }

            // The active competitors take the places they had among themselves, in the new order, so an inactive
            // competitor between them keeps its place.
            $places = $active->pluck('position')->map(fn ($position): int => (int) $position)->all();
            sort($places);
            foreach ($ids as $index => $id) {
                $competitor = $active->get($id);
                $place = $places[$index];
                // Places can repeat after a migration of equal names; make them strictly increasing.
                if ($index > 0 && $place <= $places[$index - 1]) {
                    $place = $places[$index] = $places[$index - 1] + 1;
                }
                if ($competitor !== null && (int) $competitor->position !== $place) {
                    $competitor->position = $place;
                    $competitor->save();
                }
            }
        });

        return response()->json(['color_indexes' => Competitor::colorIndexes($brand)]);
    }

    public function storeAlias(Request $request, Brand $brand, string $competitor): JsonResponse
    {
        $record = $this->competitor($brand, $competitor);
        Gate::authorize('update', $record);
        $mode = (string) $request->input('match_mode');
        $data = $request->validate([
            'alias' => ['required', 'string', 'max:200', Rule::when($mode === 'regex', [new SafeRegexAlias])],
            'match_mode' => ['required', 'string', Rule::in(self::MATCH_MODES)],
        ]);
        $alias = $mode === 'regex' ? (string) $data['alias'] : trim((string) $data['alias']);

        DB::transaction(function () use ($record, $alias, $mode): void {
            Competitor::query()->whereKey($record->id)->lockForUpdate()->first();
            if ($alias === '' || $record->aliases()->where('alias', $alias)->where('match_mode', $mode)->exists()) {
                throw ValidationException::withMessages(['alias' => __('entities.errors.duplicate')]);
            }
            $record->aliases()->create(['alias' => $alias, 'match_mode' => $mode]);
        });

        return response()->json(['competitor' => $this->payload($brand, $record)], 201);
    }

    public function updateAlias(Request $request, Brand $brand, string $competitor, string $alias): JsonResponse
    {
        $record = $this->competitor($brand, $competitor);
        Gate::authorize('update', $record);
        $row = CompetitorAlias::query()->where('competitor_id', $record->id)->findOrFail($alias);
        $mode = (string) $request->input('match_mode');
        $request->validate([
            'match_mode' => ['required', 'string', Rule::in(self::MATCH_MODES)],
        ]);
        // A word alias that becomes a regex must be a safe pattern too.
        if ($mode === 'regex') {
            validator(['alias' => $row->alias], ['alias' => [new SafeRegexAlias]])->validate();
        }

        DB::transaction(function () use ($record, $row, $mode): void {
            Competitor::query()->whereKey($record->id)->lockForUpdate()->first();
            if ($record->aliases()->where('alias', $row->alias)->where('match_mode', $mode)->whereKeyNot($row->id)->exists()) {
                throw ValidationException::withMessages(['match_mode' => __('entities.errors.duplicate')]);
            }
            $row->match_mode = $mode;
            $row->save();
        });

        return response()->json(['competitor' => $this->payload($brand, $record)]);
    }

    public function destroyAlias(Brand $brand, string $competitor, string $alias): JsonResponse
    {
        $record = $this->competitor($brand, $competitor);
        Gate::authorize('update', $record);
        CompetitorAlias::query()->where('competitor_id', $record->id)->findOrFail($alias)->delete();

        return response()->json(['competitor' => $this->payload($brand, $record)]);
    }

    public function storeDomain(Request $request, Brand $brand, string $competitor): JsonResponse
    {
        $record = $this->competitor($brand, $competitor);
        Gate::authorize('update', $record);
        $request->merge(['domain' => UrlNormalizer::hostKey(trim((string) $request->input('domain')))]);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'regex:'.self::DOMAIN],
        ]);
        $domain = (string) $data['domain'];

        DB::transaction(function () use ($record, $domain): void {
            Competitor::query()->whereKey($record->id)->lockForUpdate()->first();
            if ($record->domains()->where('domain', $domain)->exists()) {
                throw ValidationException::withMessages(['domain' => __('entities.errors.duplicate')]);
            }
            $record->domains()->create(['domain' => $domain]);
        });

        return response()->json(['competitor' => $this->payload($brand, $record)], 201);
    }

    public function destroyDomain(Brand $brand, string $competitor, string $domain): JsonResponse
    {
        $record = $this->competitor($brand, $competitor);
        Gate::authorize('update', $record);
        CompetitorDomain::query()->where('competitor_id', $record->id)->findOrFail($domain)->delete();

        return response()->json(['competitor' => $this->payload($brand, $record)]);
    }

    private function competitor(Brand $brand, string $id, bool $lock = false): Competitor
    {
        return Competitor::query()->where('brand_id', $brand->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function payload(Brand $brand, Competitor $competitor): array
    {
        $competitor->load(['aliases' => fn ($query) => $query->orderBy('id'), 'domains' => fn ($query) => $query->orderBy('id')]);

        return [
            'id' => $competitor->id,
            'name' => $competitor->name,
            'is_active' => $competitor->is_active,
            'position' => $competitor->position,
            'color_index' => Competitor::colorIndexes($brand)[$competitor->id] ?? null,
            'aliases' => $competitor->aliases->map(fn (CompetitorAlias $alias): array => [
                'id' => $alias->id, 'alias' => $alias->alias, 'match_mode' => $alias->match_mode,
            ])->all(),
            'domains' => $competitor->domains->map(fn (CompetitorDomain $domain): array => [
                'id' => $domain->id, 'domain' => $domain->domain,
            ])->all(),
        ];
    }
}
