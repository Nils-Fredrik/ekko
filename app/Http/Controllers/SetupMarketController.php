<?php

namespace App\Http\Controllers;

use App\Brands\CountryList;
use App\Dashboard\BrandPage;
use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup › Markets and measurement plan (docs/ux-arbeidsflate.md 5.4, U24).
 * Writing stays on the market and plan routes from U23.
 */
class SetupMarketController extends Controller
{
    public function __invoke(Brand $brand, MeasurementPlans $plans): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load(['markets' => fn ($query) => $query->orderBy('id')]);
        $tenant = Team::query()->findOrFail((int) app(TenantContext::class)->require());
        $ids = $brand->markets->modelKeys();
        $counts = $ids === [] ? collect() : Prompt::query()
            ->whereIn('market_id', $ids)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->selectRaw("market_id, count(*) as prompts, count(*) filter (where priority = 'core') as core")
            ->groupBy('market_id')
            ->get()
            ->keyBy('market_id');
        $selected = session('market_id');

        return Inertia::render('Brands/Setup/Markets', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'tenantName' => $tenant->name,
            'markets' => $brand->markets->map(function (Market $market) use ($counts): array {
                $row = $counts->get($market->id);

                return [
                    'id' => $market->id,
                    'country' => $market->country,
                    'language' => $market->language,
                    'name' => $market->location_label ?: strtoupper($market->country),
                    'place' => $market->location_label ?: strtoupper($market->country),
                    'location_label' => $market->location_label ?? '',
                    'is_active' => (bool) $market->is_active,
                    'plan_mode' => $market->plan_mode,
                    'prompts' => (int) ($row->prompts ?? 0),
                    'core' => (int) ($row->core ?? 0),
                ];
            })->values(),
            'measurementPlan' => $plans->present($tenant, $brand),
            'prompts' => $ids === [] ? (object) [] : Prompt::query()
                ->whereIn('market_id', $ids)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->limit(200)
                ->get(['id', 'text'])
                ->mapWithKeys(fn (Prompt $prompt): array => [$prompt->id => $prompt->text]),
            'countries' => CountryList::options(app()->getLocale()),
            'languages' => CountryList::languages(),
            'selectedMarketId' => is_numeric($selected) ? (int) $selected : null,
            'canManage' => Gate::allows('update', $brand),
        ]);
    }
}
