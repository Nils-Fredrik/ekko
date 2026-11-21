<?php

namespace App\Http\Controllers;

use App\Brands\MarketLibrary;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class MarketSetupController extends Controller
{
    public function store(Request $request, Brand $brand, MarketLibrary $markets): RedirectResponse
    {
        Gate::authorize('create', Market::class);
        $data = $request->validate([
            'country' => ['required', 'string', 'regex:/\A[a-z]{2}\z/i'],
            'language' => ['required', 'string', 'max:35', 'regex:/\A[a-z]{2,3}(-[a-z0-9]{2,8})*\z/i'],
            'location_label' => ['nullable', 'string', 'max:255', Prompt::nullByteRule()],
            'copy_from' => ['nullable', 'integer', 'min:1'],
        ]);
        $market = $markets->create($brand, $data);

        return to_route(Route::has('brands.setup.markets') ? 'brands.setup.markets' : 'brands.settings', $brand)->with('market_id', $market->id);
    }

    public function update(Request $request, Brand $brand, Market $market, MarketLibrary $markets): RedirectResponse
    {
        abort_unless($market->brand_id === $brand->id, 404);
        Gate::authorize('update', $market);
        $markets->update($market, $request->validate([
            'is_active' => ['sometimes', 'required', 'boolean'],
            'location_label' => ['sometimes', 'nullable', 'string', 'max:255', Prompt::nullByteRule()],
        ]));

        return back();
    }
}
