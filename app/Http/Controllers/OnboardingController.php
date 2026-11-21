<?php

namespace App\Http\Controllers;

use App\Integrations\BrandIntegrations;
use App\Jobs\RunOnboardingAnalysis;
use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\PromptPopulation;
use App\MeasurementPlan\StandardPlan;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\CostEstimator;
use App\Onboarding\Fetch\SiteUrl;
use App\Onboarding\Model\OnboardingSchema;
use App\Onboarding\ProposalConfirmer;
use App\Scheduling\DispatcherLauncher;
use App\Scheduling\PromptSchedule;
use App\Tenancy\TenantContext;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The backend of the onboarding wizard (docs/spec.md 13, ADR 0018). It returns proposals as JSON and saves
 * nothing: the brand, topics, competitors and prompts are only created when the user confirms (T29).
 *
 * Starting an analysis is asynchronous: fetching the site and the model call take tens of seconds, so the
 * request answers 202 with an id and the client polls the analysis until it is ready or has failed.
 */
class OnboardingController extends Controller
{
    private const LANGUAGE = '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/';

    /** The wizard page (docs/spec.md 13, T29). The measurement plan comes from the package. */
    public function create(): Response
    {
        Gate::authorize('createBrandAnalysis', Analysis::class);

        return $this->wizard();
    }

    public function createMarket(Brand $brand): Response
    {
        Gate::authorize('createMarketAnalysis', Analysis::class);

        return $this->wizard($brand);
    }

    private function wizard(?Brand $brand = null): Response
    {
        $tenant = $this->tenant();
        $plans = app(MeasurementPlans::class);
        $options = $plans->options($tenant);
        // A new pitch brand is saved with the fixed daily plan. The editor must show that plan, not a draft the
        // server will discard (docs/spec.md 7.6).
        $pitch = $brand === null && $tenant->kind === 'pitch';
        $standard = $options['defaults'];
        if ($pitch) {
            $standard = StandardPlan::pitchWizardRules($tenant->plan()->first());
        } elseif ($brand instanceof Brand) {
            $standard = $plans->present($tenant, $brand)['standard'];
        }

        return Inertia::render('Onboarding/Wizard', [
            // The same plan as brand settings: the package's choices and standard, and for a new market the brand's
            // plan that it inherits by default (docs/spec.md 7.6).
            'measurementPlan' => [
                'options' => $options,
                'standard' => $standard,
                'locked' => $pitch,
            ],
            'maxCore' => (int) config('ekko.wizard.suggestions.max_core_prompts'),
            'clickhouseEndpoints' => app(BrandIntegrations::class)->endpoints(),
            'brand' => $brand === null ? null : [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_domain' => $brand->primary_domain,
                'topics' => $brand->topics()->whereNotNull('cross_market_key')->get(['name', 'cross_market_key']),
            ],
        ]);
    }

    /**
     * Saves the edited proposal and starts the first run of its core prompts.
     */
    public function confirm(Request $request, string $analysis, AnalysisStore $store, ProposalConfirmer $confirmer, BrandIntegrations $integrations): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        abort_if($tenantId === null, 403);
        $found = $store->find($tenantId, $analysis);
        abort_if($found === null, 404);
        Gate::authorize('confirm', $found);

        $brand = $found->kind === Analysis::BRAND;
        $data = $request->validate([
            'name' => [$brand ? 'required' : 'sometimes', 'nullable', 'string', 'max:120'],
            'primary_domain' => [$brand ? 'required' : 'sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'regex:/^[a-zA-Z]{2}$/'],
            'language' => ['required', 'string', 'max:35', 'regex:'.self::LANGUAGE],
            'name_variants' => ['sometimes', 'array', 'max:10'],
            'name_variants.*' => ['string', 'max:120'],
            'competitors' => ['sometimes', 'array', 'max:10'],
            'competitors.*.name' => ['required', 'string', 'max:120'],
            'competitors.*.domain' => ['required', 'string', 'max:255'],
            'facts' => ['sometimes', 'array', 'max:'.(int) config('ekko.facts.max_per_brand')],
            'facts.*' => ['string', 'max:'.BrandFact::TEXT_MAX],
            'topics' => ['sometimes', 'array', 'max:8'],
            'topics.*.name' => ['required', 'string', 'max:100'],
            'topics.*.cross_market_key' => ['required', 'string', 'max:100'],
            'prompts' => ['sometimes', 'array', 'max:50'],
            'prompts.*.text' => ['required', 'string', 'max:'.Prompt::TEXT_MAX, Prompt::nullByteRule()],
            'prompts.*.cross_market_key' => ['required', 'string', 'max:100'],
            'prompts.*.intent' => ['required', Rule::in(OnboardingSchema::INTENTS)],
            'prompts.*.funnel_stage' => ['required', Rule::in(OnboardingSchema::FUNNEL_STAGES)],
            'prompts.*.priority' => ['required', Rule::in(['core', 'standard'])],
            'engines' => ['sometimes', 'array', 'max:20'],
            'engines.*.engine_key' => ['required', 'string', 'max:64'],
            'engines.*.frequency' => ['required', Rule::in(PromptSchedule::frequencies())],
            'engines.*.prompt_scope' => ['sometimes', Rule::in(MeasurementPlans::SCOPES)],
            'engines.*.repetitions' => ['required', 'integer', 'between:1,3'],
            // A new market inherits the brand's plan unless it is given own choices (docs/spec.md 7.6).
            'plan_mode' => [$brand ? 'prohibited' : 'sometimes', Rule::in([Market::PLAN_INHERIT, Market::PLAN_OWN])],
            ...$integrations->rules(),
        ], [], [
            'name' => __('onboarding.ui.name'),
            'primary_domain' => __('onboarding.ui.domain'),
            'country' => __('onboarding.ui.country'),
            'language' => __('onboarding.ui.language'),
            'prompts.*.text' => __('onboarding.ui.prompt'),
        ]);
        $data['language'] = $this->language((string) $data['language']);
        $data['country'] = strtoupper((string) $data['country']);

        $result = $confirmer->confirm($this->tenant(), $found, $data);

        return redirect()->route('brands.show', $result['brand']);
    }

    /** A new brand: reads the site and proposes industry, name variants, competitors, topics and prompts. */
    public function storeBrandAnalysis(Request $request, AnalysisStore $store): JsonResponse
    {
        Gate::authorize('createBrandAnalysis', Analysis::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'primary_domain' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || SiteUrl::hostFromUserInput($value) === null) {
                    $fail(__('onboarding.invalid_domain'));
                }
            }],
            'country' => ['required', 'string', 'regex:/^[a-zA-Z]{2}$/'],
            'language' => ['required', 'string', 'max:35', 'regex:'.self::LANGUAGE],
        ]);

        $tenant = $this->tenant();
        $plan = $tenant->plan()->first();
        if ($plan instanceof Plan && $plan->max_brands !== null && Brand::query()->count() >= $plan->max_brands) {
            return $this->refuse('brand_limit', 422);
        }

        return $this->start($store, $tenant, Analysis::BRAND, [
            'name' => trim($data['name']),
            // Always the bare host: the wizard fetches over https only.
            'primary_domain' => (string) SiteUrl::hostFromUserInput($data['primary_domain']),
            'country' => strtoupper($data['country']),
            'language' => $this->language($data['language']),
        ]);
    }

    /** A new market on an existing brand: proposes prompts in the new language from the brand's topics (cross_market_key). */
    public function storeMarketAnalysis(Request $request, Brand $brand, AnalysisStore $store): JsonResponse
    {
        Gate::authorize('createMarketAnalysis', Analysis::class);

        $data = $request->validate([
            'country' => ['required', 'string', 'regex:/^[a-zA-Z]{2}$/'],
            'language' => ['required', 'string', 'max:35', 'regex:'.self::LANGUAGE],
        ]);

        $tenant = $this->tenant();
        $plan = $tenant->plan()->first();
        if ($plan instanceof Plan && $plan->max_markets_per_brand !== null && $brand->markets()->count() >= $plan->max_markets_per_brand) {
            return $this->refuse('market_limit', 422);
        }
        if ($brand->markets()->where('country', strtoupper($data['country']))->where('language', $this->language($data['language']))->exists()) {
            throw ValidationException::withMessages(['country' => __('onboarding.errors.market_exists')]);
        }
        if (! Topic::query()->where('brand_id', $brand->id)->whereNotNull('cross_market_key')->exists()) {
            return $this->refuse('no_topics', 422);
        }

        return $this->start($store, $tenant, Analysis::MARKET, [
            'brand_id' => $brand->id,
            'country' => strtoupper($data['country']),
            'language' => $this->language($data['language']),
        ]);
    }

    public function show(Request $request, string $analysis, AnalysisStore $store): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        abort_if($tenantId === null, 403);

        $found = $store->find($tenantId, $analysis);
        abort_if($found === null, 404);

        Gate::authorize('view', $found);

        return response()->json($this->present($found));
    }

    /**
     * Estimated monthly cost for an engine setup, from the plan, engine prices and this month's usage (docs/spec.md 7.4).
     * The prompt counts come from the request or, for an existing market, from its active prompts.
     */
    public function estimate(Request $request, CostEstimator $estimator): JsonResponse
    {
        Gate::authorize('estimate', Analysis::class);

        $data = $request->validate([
            'standard' => ['sometimes', 'array:engines', 'prohibits:engines,market_id'],
            'standard.engines' => ['present_with:standard', 'array'],
            'engines' => ['required_without:standard', 'array', 'min:1', 'max:20'],
            'engines.*.engine_key' => ['required', 'string', 'max:64'],
            'engines.*.frequency' => ['required', Rule::in(PromptSchedule::frequencies())],
            'engines.*.prompt_scope' => ['sometimes', Rule::in(['all', 'core'])],
            'engines.*.repetitions' => ['sometimes', 'integer', 'between:1,3'],
            'market_id' => ['sometimes', 'nullable', 'integer'],
            'prompt_count' => ['required_without:market_id', 'integer', 'min:0', 'max:1000'],
            'core_prompt_count' => $request->has('standard')
                ? ['required', 'integer', 'min:0', 'lte:prompt_count']
                : ['sometimes', 'integer', 'between:0,1000'],
        ]);

        $tenant = $this->tenant();
        $market = isset($data['market_id']) ? Market::query()->findOrFail($data['market_id']) : null;
        $population = $market === null ? null : (PromptPopulation::forMarkets([$market->id])[$market->id] ?? new PromptPopulation);
        $count = (int) ($data['prompt_count'] ?? $population->total ?? 0);
        $core = min((int) ($data['core_prompt_count'] ?? $population->core ?? config('ekko.wizard.suggestions.max_core_prompts')), $count);
        $legacy = ! isset($data['standard']);
        if ($legacy) {
            $setup = array_map(fn (array $row): array => [
                'engine_key' => $row['engine_key'], 'frequency' => $row['frequency'],
                'prompt_scope' => $row['prompt_scope'] ?? 'all', 'repetitions' => (int) ($row['repetitions'] ?? 1),
            ], array_values($data['engines']));
        } else {
            $errors = [];
            $engines = $tenant->kind === 'pitch' ? StandardPlan::pitchWizardRules($tenant->plan()->first()) : ($data['standard']['engines'] ?? null);
            $setup = app(MeasurementPlans::class)->validateRules($tenant, $engines, 'standard.engines', $errors);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        $result = $estimator->estimate($tenant, [[
            'id' => $market?->id, 'is_active' => $market->is_active ?? true, 'plan_mode' => $market->plan_mode ?? Market::PLAN_INHERIT,
            'engines' => $setup, 'prompts' => new PromptPopulation($count, $core, $population?->coreExample, $population?->standardExample),
        ]], legacyProposal: $legacy);

        return response()->json($result + ['calls' => $result['answers'], 'prompt_count' => $count, 'core_prompt_count' => $core]);
    }

    /**
     * Checks the quota and creates the analysis under the tenant's lock, counting the analyses that are started but
     * not billed yet, so parallel requests cannot all pass on the same remaining quota. The job is dispatched after
     * the lock is released: with a synchronous queue it finishes the analysis, which takes the lock too.
     *
     * @param  array<string, mixed>  $input
     */
    private function start(AnalysisStore $store, Team $tenant, string $kind, array $input): JsonResponse
    {
        $tenantId = $tenant->id;
        try {
            $analysis = $store->reserving($tenantId, fn (int $pending): ?Analysis => $this->quotaAllows($tenant, $pending)
                ? $store->create($tenantId, (int) auth()->id(), $kind, $input)
                : null);
        } catch (LockTimeoutException) {
            return $this->refuse('busy', 429);
        }
        if ($analysis === null) {
            return $this->refuse('quota_reached', 429);
        }

        RunOnboardingAnalysis::dispatch($tenantId, $analysis->id);

        // The fixed poll dispatcher wakes the worker without granting web worker overrides. It starts once the request's
        // transaction has committed the queued job, inside the request: on Cloud Run with CPU only during
        // requests, work after the response may never finish. A failure to start is reported, not shown: the
        // next dispatch-due or extraction-poll starts the worker as well.
        DB::afterCommit(function (): void {
            try {
                app(DispatcherLauncher::class)->start();
            } catch (LockTimeoutException) {
                // Another request or the scheduler is starting the worker right now.
            } catch (Throwable $e) {
                report($e);
            }
        });

        // Re-read: with a synchronous queue (local development, tests) the job has already finished.
        $current = $store->find($tenantId, $analysis->id) ?? $analysis;

        return response()->json($this->present($current), 202, ['Location' => route('onboarding.analyses.show', ['analysis' => $analysis->id])]);
    }

    /** @return array<string, mixed> */
    private function present(Analysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'kind' => $analysis->kind,
            'status' => $analysis->status,
            'input' => $analysis->input,
            'proposal' => $analysis->proposal,
            'pages' => $analysis->pages,
            'warnings' => array_map(fn (string $code): array => ['code' => $code, 'message' => __("onboarding.warnings.{$code}")], $analysis->warnings),
            'error' => $analysis->error === null ? null : ['code' => $analysis->error, 'message' => __("onboarding.errors.{$analysis->error}")],
            // Nothing is saved until the user confirms the proposal.
            'saved' => false,
        ];
    }

    private function refuse(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => __("onboarding.errors.{$code}")]], $status);
    }

    /** Whether one more analysis fits, with $pending started analyses counted as one call and the estimated cost each. */
    private function quotaAllows(Team $tenant, int $pending): bool
    {
        $estimate = (string) config('ekko.anthropic.onboarding.estimated_cost_usd');
        $usage = app(MonthlyUsage::class)->forAdmission($tenant, CarbonImmutable::now('UTC'));
        $usage->calls = (int) $usage->calls + $pending;
        $usage->cost_usd = (string) BigDecimal::of((string) $usage->cost_usd)->plus(BigDecimal::of($estimate)->multipliedBy($pending));
        $planned = new PlannedRun(0, true, BillingAccount::Platform, $estimate);

        return app(QuotaGuard::class)->allows($usage, $planned);
    }

    private function tenant(): Team
    {
        return Team::query()->findOrFail((int) app(TenantContext::class)->require());
    }

    private function language(string $language): string
    {
        $parts = explode('-', $language);
        $parts[0] = strtolower($parts[0]);

        return implode('-', $parts);
    }
}
