<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Models\Alert;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The setup and inbox routes of the workspace (docs/ux-arbeidsflate.md 6.1). Each forwards to today's
 * page until the task that builds the page replaces the redirect: U20 prompts, U22 competitors,
 * U24 markets, identity and integrations, U26 notifications and the inbox.
 */
class WorkspaceRedirectController extends Controller
{
    public function prompts(Request $request, Brand $brand): RedirectResponse
    {
        BrandPage::authorizeView($brand);

        return redirect()->route('brands.prompts.index', ['brand' => $brand->id, ...$this->market($request, $brand)]);
    }

    public function competitors(Request $request, Brand $brand): RedirectResponse
    {
        BrandPage::authorizeView($brand);

        return redirect()->route('brands.competitors.index', ['brand' => $brand->id, ...$this->market($request, $brand)]);
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
        BrandPage::authorizeView($brand);

        return redirect()->route('alerts.index');
    }

    public function inbox(): RedirectResponse
    {
        Gate::authorize('viewAny', Alert::class);

        return redirect()->route('alerts.index');
    }

    private function settings(Brand $brand): RedirectResponse
    {
        BrandPage::authorizeView($brand);

        return redirect()->route('brands.settings', $brand->id);
    }

    /**
     * The market in scope, kept only when it belongs to the brand.
     *
     * @return array{market?: int}
     */
    private function market(Request $request, Brand $brand): array
    {
        $ids = BrandPage::marketIds($brand, $request);

        return $ids === [] ? [] : ['market' => $ids[0]];
    }
}
