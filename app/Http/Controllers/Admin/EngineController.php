<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Plan;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class EngineController extends Controller
{
    public function index(TenantRls $rls): Response
    {
        Gate::authorize('viewAny', Plan::class);
        $health = $this->health($rls);
        $engines = [];
        foreach (Engine::query()->with('prices')->orderBy('key')->get() as $engine) {
            $price = $engine->prices->sortByDesc(fn (EnginePrice $price): int => $price->valid_from->getTimestamp())->first();
            $row = $health[$engine->key] ?? ['total' => 0, 'failed' => 0];
            $engines[] = [
                'key' => $engine->key,
                'model_id' => $engine->model_id,
                'is_enabled' => $engine->is_enabled,
                'available' => $engine->isAvailable(),
                'price' => $price === null ? null : [
                    'valid_from' => $price->valid_from->toIso8601String(),
                    'price_per_call_usd' => $price->price_per_call_usd,
                    'price_per_1k_input_tokens_usd' => $price->price_per_1k_input_tokens_usd,
                    'price_per_1k_output_tokens_usd' => $price->price_per_1k_output_tokens_usd,
                    'price_per_search_usd' => $price->price_per_search_usd,
                ],
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
        EnginePrice::query()->updateOrCreate(
            ['engine_key' => $engine->key, 'valid_from' => $data['valid_from']],
            $data,
        );

        return back(303);
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

        $rows = $rls->run($ids, fn () => DB::table('runs')
            ->where('finished_at', '>=', now()->subDay())
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
