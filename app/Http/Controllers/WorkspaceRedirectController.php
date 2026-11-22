<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Setup pages that are not built yet (docs/ux-arbeidsflate.md 4.1 and 6.1, U17).
 * Each task replaces its own route. Prompts, the inbox and notifications already have pages.
 */
class WorkspaceRedirectController extends Controller
{
    public function competitors(Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return redirect()->route('brands.competitors.index', $brand);
    }

    public function markets(Brand $brand): RedirectResponse
    {
        return $this->settings($brand);
    }

    public function identity(Brand $brand): RedirectResponse
    {
        return $this->settings($brand);
    }

    public function integrations(Brand $brand): RedirectResponse
    {
        return $this->settings($brand);
    }

    private function settings(Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return redirect()->route('brands.settings', $brand);
    }
}
