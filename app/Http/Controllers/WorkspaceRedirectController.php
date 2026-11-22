<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Setup pages and the inbox that are not built yet (docs/ux-arbeidsflate.md 4.1 and 6.1, U17).
 * Each task replaces its own route. The prompt page is SetupPromptController.
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

    public function notifications(Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return redirect()->route('alerts.index');
    }

    public function inbox(): RedirectResponse
    {
        Gate::authorize('viewAny', Alert::class);

        return redirect()->route('alerts.index');
    }

    private function settings(Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return redirect()->route('brands.settings', $brand);
    }
}
