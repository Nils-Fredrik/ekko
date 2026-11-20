<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Models\Brand;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SavedViewController extends Controller
{
    public function store(Request $request, Brand $brand): JsonResponse
    {
        Gate::authorize('view', $brand);
        Gate::authorize('create', SavedView::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'page' => ['required', Rule::in(AnalysisFilters::PAGES)],
            'filters' => ['required', 'array:days,from,to,comparison,market,engine,topic,intent,competitor,grain'],
        ]);
        $query = AnalysisFilters::query($brand, $data['filters'], $data['page']);
        $view = SavedView::query()->updateOrCreate([
            'user_id' => $request->user()->id, 'brand_id' => $brand->id, 'page' => $data['page'], 'name' => $data['name'],
        ], ['filters' => $query]);

        return response()->json($view->only(['id', 'name', 'filters']), 201);
    }

    public function destroy(Request $request, Brand $brand, SavedView $savedView): JsonResponse
    {
        Gate::authorize('view', $brand);
        abort_unless($savedView->brand_id === $brand->id && $savedView->user_id === $request->user()->id, 404);
        Gate::authorize('delete', $savedView);
        $savedView->delete();

        return response()->json(['deleted' => true]);
    }
}
