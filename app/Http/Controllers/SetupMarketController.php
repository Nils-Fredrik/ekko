<?php

namespace App\Http\Controllers;

use App\Brands\CountryList;
use App\Dashboard\BrandPage;
use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\PlanDraft;
use App\MeasurementPlan\PromptPopulation;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\RunBatch;
use App\Models\Team;
use App\Onboarding\CostEstimator;
use App\Scheduling\NextMeasurement;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup › Markets and measurement plan (docs/ux-arbeidsflate.md 5.4, U24).
 * Writing stays on the market and plan routes from U23.
 */
class SetupMarketController extends Controller
{
    public function __invoke(Brand $brand, MeasurementPlans $plans, CostEstimator $estimator, NextMeasurement $next): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load(['markets' => fn ($query) => $query->orderBy('id')]);
        $tenant = Team::query()->findOrFail((int) app(TenantContext::class)->require());
        $ids = $brand->markets->modelKeys();
        $due = $this->nextMeasurements($brand, $tenant, $plans, $estimator, $next);
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
            'markets' => $brand->markets->map(function (Market $market) use ($counts, $due): array {
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
                    'next_due_on' => $market->is_active ? ($due[$market->id] ?? null) : null,
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

    /**
     * Next measurement from the saved plan, for every role. The live estimate is the unsaved draft and is not
     * available to readers.
     *
     * @return array<int, ?string>
     */
    private function nextMeasurements(Brand $brand, Team $tenant, MeasurementPlans $plans, CostEstimator $estimator, NextMeasurement $next): array
    {
        $populations = PromptPopulation::forMarkets($brand->markets->modelKeys());
        $input = [];
        foreach ($plans->preview($brand, new PlanDraft(null, [])) as $id => $settings) {
            if ($settings['is_active']) {
                $input[] = $settings + ['id' => $id, 'prompts' => $populations[$id] ?? new PromptPopulation];
            }
        }
        $estimate = $estimator->estimate($tenant, $input);
        $today = CarbonImmutable::today($tenant->timezone);
        $active = array_column($input, 'id');
        $planned = $active === [] ? collect() : RunBatch::query()->whereIn('market_id', $active)
            ->where('dispatch_date', $today->toDateString())->pluck('market_id')->flip();
        $prompts = $active === [] ? collect() : Prompt::query()->whereIn('market_id', $active)->where('is_active', true)
            ->get(['id', 'market_id', 'priority'])->groupBy('market_id');
        $due = [];
        foreach ($estimate['markets'] as $market) {
            $id = $market['id'];
            $start = $planned->has($id) ? $today->addDay() : $today;
            $due[$id] = $next->on($market['rules'], $prompts->get($id, collect()), $start);
        }

        return $due;
    }
}
