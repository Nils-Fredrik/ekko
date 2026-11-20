<?php

namespace App\Http\Controllers\Api;

use App\Api\ActionInput;
use App\Api\ActionQuery;
use App\Api\ProductData;
use App\Api\Query;
use App\Models\Action;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ActionController
{
    public function index(Request $request, Brand $brand): JsonResponse
    {
        return response()->json(ActionQuery::forBrand($brand, $request->all())->paginate(Query::pageSize($request))
            ->withQueryString()->through(fn (Action $action) => ProductData::action($action)));
    }

    public function show(Action $action): JsonResponse
    {
        Gate::authorize('view', $action);

        return response()->json(['data' => ProductData::action($action->load('prompts:id'))]);
    }

    public function store(Request $request, Brand $brand, ActionInput $input): JsonResponse
    {
        return response()->json(['data' => ProductData::action($input->create($brand, $request->all())->load('prompts:id'))], 201);
    }

    public function update(Request $request, Brand $brand, ActionInput $input): JsonResponse
    {
        return response()->json(['data' => ProductData::action($input->update($brand, $request->all())->load('prompts:id'))]);
    }
}
