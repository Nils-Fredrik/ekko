<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Engine;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PlanController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Plan::class);

        return Inertia::render('Admin/Plans', [
            'plans' => Plan::query()->orderBy('name')->get(),
            'engines' => Engine::query()->orderBy('key')->get(['key']),
            'frequencies' => ['daily', 'weekly', 'monthly'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Plan::class);
        Plan::query()->create($this->validated($request));

        return back(303);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('update', $plan);
        $plan->update($this->validated($request));

        return back(303);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'max_brands' => ['nullable', 'integer', 'min:1'],
            'max_markets_per_brand' => ['nullable', 'integer', 'min:1'],
            'max_prompts_per_market' => ['nullable', 'integer', 'min:1'],
            'monthly_call_quota' => ['nullable', 'integer', 'min:0'],
            'monthly_budget_usd' => ['nullable', 'numeric', 'min:0'],
            'max_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'max_repetitions' => ['required', 'integer', 'between:1,3'],
            'allowed_engines' => ['present', 'array'],
            'allowed_engines.*' => ['string', 'exists:engines,key'],
            'engine_max_frequencies' => ['present', 'array'],
            'engine_max_frequencies.*' => ['string', Rule::in(['daily', 'weekly', 'monthly'])],
        ]);

        $allowed = array_values(array_unique($data['allowed_engines'] ?? []));
        $frequencies = [];
        foreach ($data['engine_max_frequencies'] ?? [] as $engine => $frequency) {
            if (in_array($engine, $allowed, true) && $frequency !== $data['max_frequency']) {
                $frequencies[$engine] = $frequency;
            }
        }

        return [
            'name' => $data['name'],
            'max_brands' => $data['max_brands'] ?? null,
            'max_markets_per_brand' => $data['max_markets_per_brand'] ?? null,
            'max_prompts_per_market' => $data['max_prompts_per_market'] ?? null,
            'monthly_call_quota' => $data['monthly_call_quota'] ?? null,
            'monthly_budget_usd' => $data['monthly_budget_usd'] ?? null,
            'max_frequency' => $data['max_frequency'],
            'max_repetitions' => $data['max_repetitions'],
            'allowed_engines' => $allowed,
            'engine_max_frequencies' => $frequencies,
        ];
    }
}
