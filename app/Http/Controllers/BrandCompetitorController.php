<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Team;
use App\Prompts\UnknownBrands;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Competitors, their aliases and domains, and unknown brands that can be added in one click
 * (docs/spec.md 14.2, T27).
 */
class BrandCompetitorController extends Controller
{
    public function index(Request $request, Brand $brand): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');
        $period = BrandPage::period($request);
        $marketIds = BrandPage::marketIds($brand, $request);

        $competitors = Competitor::query()
            ->where('brand_id', $brand->id)
            ->with(['aliases', 'domains'])
            ->orderBy('name')
            ->get()
            ->map(fn (Competitor $competitor): array => [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'is_active' => $competitor->is_active,
                'aliases' => $competitor->aliases->pluck('alias')->all(),
                'domains' => $competitor->domains->pluck('domain')->all(),
            ])->all();

        return Inertia::render('Brands/Competitors', [
            'brand' => BrandPage::payload($brand),
            'period' => ['days' => $period->days],
            'filters' => ['market' => $marketIds[0] ?? null],
            'markets' => BrandPage::markets($brand),
            'competitors' => $competitors,
            'suggestions' => UnknownBrands::suggestions($brand, $period, $marketIds),
            'canManage' => Gate::allows('create', Competitor::class),
        ]);
    }

    public function store(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('create', Competitor::class);

        $name = trim((string) $request->validate([
            'name' => ['required', 'string', 'max:200'],
        ])['name']);

        DB::transaction(function () use ($brand, $name): void {
            // Conversion and onboarding take this same lock before changing pitch state.
            $tenant = Team::query()->whereKey(current_tenant_id())->lock('for no key update')->firstOrFail();
            // One lock per brand, so two clicks cannot both pass the duplicate check.
            Brand::query()->whereKey($brand->id)->lockForUpdate()->first();

            if ($name === '' || isset(UnknownBrands::knownNames($brand)[mb_strtolower($name)])) {
                throw ValidationException::withMessages(['name' => __('app.library.duplicate')]);
            }

            if ($tenant->kind === 'pitch' && $brand->competitors()->count() >= 3) {
                throw ValidationException::withMessages(['name' => __('pitch.limits')]);
            }

            $competitor = $brand->competitors()->create([
                'name' => $name,
                'is_active' => true,
            ]);
            $competitor->aliases()->create([
                'alias' => $name,
                'match_mode' => 'case_insensitive',
            ]);
        });

        return back();
    }
}
