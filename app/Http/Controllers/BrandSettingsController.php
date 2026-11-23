<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BrandSettingsController extends Controller
{
    public function show(Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return to_route('brands.setup.identity', $brand);
    }
}
