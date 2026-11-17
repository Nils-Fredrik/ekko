<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PromptResource;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PromptController extends Controller
{
    public function store(Request $request, Brand $brand): Response
    {
        Gate::authorize('view', $brand);
        Gate::authorize('create', Prompt::class);
        $data = $this->validated($request, true);
        // Match MCP: lock the market before checking active capacity or locking a prompt.
        $market = Market::query()->where('brand_id', $brand->id)->lockForUpdate()->findOrFail($data['market_id']);
        $this->topic($data, $brand);
        if ($data['is_active'] ?? true) {
            $this->checkActiveCapacity($market->id);
        }
        $data['text_hash'] = hash('sha256', $data['text']);
        $this->unique($market->id, $data['text_hash']);
        $prompt = Prompt::query()->create($data + ['priority' => 'standard', 'is_active' => true]);

        return (new PromptResource($prompt))->response()->setStatusCode(201);
    }

    public function update(Request $request, Brand $brand): PromptResource
    {
        Gate::authorize('view', $brand);
        $id = $request->validate(['id' => ['required', 'integer', 'min:1']])['id'];
        $prompt = $this->prompt($brand, $id);
        Gate::authorize('update', $prompt);
        $data = $this->validated($request, false);
        if (! $prompt->is_active && ($data['is_active'] ?? false)) {
            $this->checkActiveCapacity($prompt->market_id);
        }
        $this->topic($data, $brand);
        if (isset($data['text'])) {
            $data['text_hash'] = hash('sha256', $data['text']);
            $this->unique($prompt->market_id, $data['text_hash'], $prompt->id);
        }
        $prompt->fill($data)->save();

        return new PromptResource($prompt);
    }

    public function destroy(Request $request, Brand $brand): Response
    {
        Gate::authorize('view', $brand);
        $id = $request->validate(['id' => ['required', 'integer', 'min:1']])['id'];
        $prompt = $this->prompt($brand, $id);
        Gate::authorize('delete', $prompt);
        $prompt->delete();

        return response()->noContent();
    }

    private function prompt(Brand $brand, int $id): Prompt
    {
        $query = Prompt::query()->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id));
        $marketId = (clone $query)->findOrFail($id)->market_id;
        // MCP also locks markets before saving prompts. Re-read the prompt after acquiring the market
        // lock so capacity checks use its current state, not a snapshot from before a competing write.
        Market::query()->where('brand_id', $brand->id)->lockForUpdate()->findOrFail($marketId);

        return $query->where('market_id', $marketId)->lockForUpdate()->findOrFail($id);
    }

    private function checkActiveCapacity(int $marketId): void
    {
        $limit = Team::query()->findOrFail(current_tenant_id())->plan?->max_prompts_per_market;
        if ($limit !== null && Prompt::query()->where('market_id', $marketId)->where('is_active', true)->count() >= $limit) {
            throw ValidationException::withMessages(['market_id' => __('api.prompt_limit')]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'market_id' => $creating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'text' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:'.Prompt::TEXT_MAX, Prompt::nullByteRule()],
            'intent' => [$creating ? 'required' : 'sometimes', 'required', Rule::in(['category', 'comparison', 'brand', 'problem', 'local'])],
            'funnel_stage' => [$creating ? 'required' : 'sometimes', 'required', Rule::in(['awareness', 'consideration', 'decision'])],
            'priority' => ['sometimes', Rule::in(['core', 'standard'])],
            'is_active' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function topic(array $data, Brand $brand): void
    {
        if (isset($data['topic_id'])) {
            Topic::query()->where('brand_id', $brand->id)->findOrFail($data['topic_id']);
        }
    }

    private function unique(int $marketId, string $hash, ?int $except = null): void
    {
        if (Prompt::query()->where('market_id', $marketId)->where('text_hash', $hash)->when($except !== null, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['text' => __('api.duplicate_prompt')]);
        }
    }
}
