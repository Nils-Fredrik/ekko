<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Plan;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Engines are platform catalogue data. Access uses PlanPolicy, which is the platform-admin gate;
 * there is no separate engine role.
 */
final class EngineController extends Controller
{
    public function index(TenantRls $rls): Response
    {
        Gate::authorize('viewAny', Plan::class);
        $health = $this->health($rls);
        $now = CarbonImmutable::now('UTC');
        $engines = [];
        foreach (Engine::query()->with('prices')->orderBy('key')->get() as $engine) {
            $current = $engine->prices
                ->filter(fn (EnginePrice $price): bool => $price->valid_from->lessThanOrEqualTo($now))
                ->sortByDesc(fn (EnginePrice $price): int => $price->valid_from->getTimestamp())
                ->first();
            $scheduled = $engine->prices
                ->filter(fn (EnginePrice $price): bool => $price->valid_from->greaterThan($now))
                ->sortBy(fn (EnginePrice $price): int => $price->valid_from->getTimestamp())
                ->first();
            $row = $health[$engine->key] ?? ['total' => 0, 'failed' => 0];
            $engines[] = [
                'key' => $engine->key,
                'model_id' => $engine->model_id,
                'is_enabled' => $engine->is_enabled,
                'available' => $engine->isAvailable(),
                'price' => $this->pricePayload($current),
                'scheduled_price' => $this->pricePayload($scheduled),
                'runs' => $row['total'],
                'failed' => $row['failed'],
                'unhealthy' => $row['total'] > 0 && ($row['failed'] / $row['total']) > 0.2,
            ];
        }

        return Inertia::render('Admin/Engines', ['engines' => $engines]);
    }

    public function update(Request $request, Engine $engine): RedirectResponse
    {
        Gate::authorize('create', Plan::class);
        $data = $request->validate([
            'model_id' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['required', 'boolean'],
        ]);
        $engine->update([
            'model_id' => $data['model_id'] !== '' ? $data['model_id'] : null,
            'is_enabled' => $data['is_enabled'],
        ]);

        return back(303);
    }

    public function storePrice(Request $request, Engine $engine): RedirectResponse
    {
        Gate::authorize('create', Plan::class);
        $data = $request->validate([
            'valid_from' => ['required', 'date'],
            'price_per_call_usd' => ['required', 'numeric', 'min:0'],
            'price_per_1k_input_tokens_usd' => ['required', 'numeric', 'min:0'],
            'price_per_1k_output_tokens_usd' => ['required', 'numeric', 'min:0'],
            'price_per_search_usd' => ['required', 'numeric', 'min:0'],
        ]);
        // The form has no offset. Treat the clock time as UTC so a later price cannot be
        // stored as an earlier instant in the app timezone, and so billed history stays put.
        $validFrom = CarbonImmutable::parse($data['valid_from'], 'UTC');
        if ($validFrom->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['valid_from' => __('admin.engines.future_price')]);
        }
        if (EnginePrice::query()->where('engine_key', $engine->key)->where('valid_from', $validFrom)->exists()) {
            throw ValidationException::withMessages(['valid_from' => __('admin.engines.existing_price')]);
        }
        EnginePrice::query()->create([
            'engine_key' => $engine->key,
            'valid_from' => $validFrom,
            'price_per_call_usd' => $data['price_per_call_usd'],
            'price_per_1k_input_tokens_usd' => $data['price_per_1k_input_tokens_usd'],
            'price_per_1k_output_tokens_usd' => $data['price_per_1k_output_tokens_usd'],
            'price_per_search_usd' => $data['price_per_search_usd'],
        ]);

        return back(303);
    }

    /**
     * @return array{valid_from: string, price_per_call_usd: string, price_per_1k_input_tokens_usd: string, price_per_1k_output_tokens_usd: string, price_per_search_usd: string}|null
     */
    private function pricePayload(?EnginePrice $price): ?array
    {
        if ($price === null) {
            return null;
        }

        return [
            'valid_from' => $price->valid_from->toIso8601String(),
            'price_per_call_usd' => $price->price_per_call_usd,
            'price_per_1k_input_tokens_usd' => $price->price_per_1k_input_tokens_usd,
            'price_per_1k_output_tokens_usd' => $price->price_per_1k_output_tokens_usd,
            'price_per_search_usd' => $price->price_per_search_usd,
        ];
    }

    /**
     * @return array<string, array{total: int, failed: int}>
     */
    private function health(TenantRls $rls): array
    {
        $ids = Team::query()->where('personal_team', false)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        // Quota skips never called a provider. Counting them would hide a high failure rate.
        $rows = $rls->run($ids, fn () => DB::table('runs')
            ->where('finished_at', '>=', now()->subDay())
            ->whereIn('status', ['collected', 'extracted', 'failed'])
            ->selectRaw("engine_key, count(*) as total, count(*) filter (where status = 'failed') as failed")
            ->groupBy('engine_key')
            ->get());

        $health = [];
        foreach ($rows as $row) {
            $health[(string) $row->engine_key] = ['total' => (int) $row->total, 'failed' => (int) $row->failed];
        }

        return $health;
    }
}
