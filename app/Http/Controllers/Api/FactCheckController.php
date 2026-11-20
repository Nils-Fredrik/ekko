<?php

namespace App\Http\Controllers\Api;

use App\Api\ProductData;
use App\Api\Query;
use App\FactCheck\BrandFactManager;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class FactCheckController
{
    public function facts(Request $request, Brand $brand): JsonResponse
    {
        Gate::authorize('view', $brand);

        return response()->json(BrandFact::query()->where('brand_id', $brand->id)->orderBy('id')
            ->paginate(Query::pageSize($request))->withQueryString()->through(fn (BrandFact $fact) => ProductData::fact($fact)));
    }

    public function claims(Request $request, Brand $brand): JsonResponse
    {
        Gate::authorize('view', $brand);
        $data = $request->validate(['verdict' => ['nullable', Rule::in(Claim::VERDICTS)]]);

        return response()->json(Claim::query()->where('brand_id', $brand->id)
            ->when(isset($data['verdict']), fn ($q) => $q->where('verdict', $data['verdict']))->orderBy('id')
            ->paginate(Query::pageSize($request))->withQueryString()->through(fn (Claim $claim) => ProductData::claim($claim)));
    }

    public function store(Request $request, Brand $brand, BrandFactManager $facts): JsonResponse
    {
        Gate::authorize('view', $brand);
        Gate::authorize('create', BrandFact::class);
        $data = $request->validate(['text' => ['required', 'string', 'max:300'], 'is_active' => ['sometimes', 'boolean'], 'tenant_id' => ['prohibited']]);

        return response()->json(['data' => ProductData::fact($facts->add($brand, $data['text'], $data['is_active'] ?? true))], 201);
    }

    public function update(Request $request, Brand $brand, BrandFactManager $facts): JsonResponse
    {
        Gate::authorize('create', BrandFact::class);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1'], 'text' => ['sometimes', 'required', 'string', 'max:300'], 'is_active' => ['sometimes', 'boolean'], 'tenant_id' => ['prohibited']]);
        $fact = BrandFact::query()->where('brand_id', $brand->id)->findOrFail($data['id']);
        Gate::authorize('update', $fact);

        return response()->json(['data' => ProductData::fact($facts->update($fact, $data['text'] ?? null, $data['is_active'] ?? null))]);
    }

    public function destroy(Request $request, Brand $brand, BrandFactManager $facts): Response
    {
        Gate::authorize('create', BrandFact::class);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $fact = BrandFact::query()->where('brand_id', $brand->id)->findOrFail($data['id']);
        Gate::authorize('delete', $fact);
        $facts->delete($fact);

        return response()->noContent();
    }
}
