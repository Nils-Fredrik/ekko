<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PromptResource;
use App\Models\Brand;
use App\Models\Prompt;
use App\Prompts\PromptLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PromptController extends Controller
{
    private readonly PromptLibrary $library;

    public function __construct()
    {
        $this->library = new PromptLibrary(limitField: 'market_id', limitMessage: 'api.prompt_limit', duplicateMessage: 'api.duplicate_prompt');
    }

    public function store(Request $request, Brand $brand): Response
    {
        Gate::authorize('view', $brand);
        Gate::authorize('create', Prompt::class);
        $data = $this->validated($request, true);
        $market = $brand->markets()->findOrFail($data['market_id']);
        $prompt = $this->library->create($market, $data);

        return (new PromptResource($prompt))->response()->setStatusCode(201);
    }

    public function update(Request $request, Brand $brand): PromptResource
    {
        Gate::authorize('view', $brand);
        $id = $request->validate(['id' => ['required', 'integer', 'min:1']])['id'];
        $prompt = $this->prompt($brand, $id);
        Gate::authorize('update', $prompt);
        $data = $this->validated($request, false);

        return new PromptResource($this->library->update($prompt, $data));
    }

    public function destroy(Request $request, Brand $brand): Response
    {
        Gate::authorize('view', $brand);
        $id = $request->validate(['id' => ['required', 'integer', 'min:1']])['id'];
        $prompt = $this->prompt($brand, $id);
        Gate::authorize('delete', $prompt);
        $this->library->delete($prompt, includingHistory: true);

        return response()->noContent();
    }

    private function prompt(Brand $brand, int $id): Prompt
    {
        return Prompt::query()->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'market_id' => $creating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            ...PromptLibrary::rules($creating),
            'tenant_id' => ['prohibited'],
        ]);
    }
}
