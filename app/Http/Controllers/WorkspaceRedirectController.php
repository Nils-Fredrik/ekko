<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The sidebar's setup pages and the inbox (docs/ux-arbeidsflate.md 4.1 and 6.1, U17). Until the task that builds
 * a page replaces its route, the route sends the user to today's page. Only `market` is passed on, and only to
 * the prompt page, which is the one setup page that is scoped by market.
 */
class WorkspaceRedirectController extends Controller
{
    public function prompts(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('view', $brand);

        return redirect()->route('brands.prompts.index', ['brand' => $brand, ...$this->market($request)]);
    }

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

    /** @return array{market?: int} */
    private function market(Request $request): array
    {
        $market = filter_var($request->query('market'), FILTER_VALIDATE_INT);

        return $market === false ? [] : ['market' => $market];
    }
}
