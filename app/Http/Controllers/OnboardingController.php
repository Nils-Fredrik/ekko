<?php

namespace App\Http\Controllers;

use App\Jobs\RunOnboardingAnalysis;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\CostEstimator;
use App\Onboarding\Fetch\SiteUrl;
use App\Tenancy\TenantContext;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
        if (! $this->quotaAllows($tenant)) {
            return $this->refuse('quota_reached', 429);
        }

        return $this->start($store, Analysis::BRAND, [
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
        if (! Topic::query()->where('brand_id', $brand->id)->whereNotNull('cross_market_key')->exists()) {
            return $this->refuse('no_topics', 422);
        }
        if (! $this->quotaAllows($tenant)) {
            return $this->refuse('quota_reached', 429);
        }

        return $this->start($store, Analysis::MARKET, [
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
            'engines' => ['required', 'array', 'min:1', 'max:20'],
            'engines.*.engine_key' => ['required', 'string', 'max:64'],
            'engines.*.frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'off'])],
            'engines.*.prompt_scope' => ['sometimes', Rule::in(['all', 'core'])],
            'engines.*.repetitions' => ['sometimes', 'integer', 'between:1,3'],
            'market_id' => ['sometimes', 'nullable', 'integer'],
            'prompt_count' => ['required_without:market_id', 'integer', 'min:0', 'max:1000'],
            'core_prompt_count' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $promptCount = $data['prompt_count'] ?? null;
        $core = $data['core_prompt_count'] ?? null;
        if (isset($data['market_id'])) {
            // Tenant scope and RLS: another tenant's market is a 404.
            $market = Market::query()->findOrFail($data['market_id']);
            $active = Prompt::query()->where('market_id', $market->id)->where('is_active', true);
            $promptCount ??= (clone $active)->count();
            $core ??= (clone $active)->where('priority', 'core')->count();
        }
        $core = min($core ?? min((int) config('ekko.wizard.suggestions.max_core_prompts'), (int) $promptCount), (int) $promptCount);

        $setup = array_map(fn (array $row): array => [
            'engine_key' => $row['engine_key'],
            'frequency' => $row['frequency'],
            'prompt_scope' => $row['prompt_scope'] ?? 'all',
            'repetitions' => (int) ($row['repetitions'] ?? 1),
        ], array_values($data['engines']));

        $result = $estimator->estimate($this->tenant(), $setup, (int) $promptCount, $core);
        $result['issues'] = array_map(fn (array $issue): array => $issue + [
            'message' => __("onboarding.issues.{$issue['code']}", ['engine' => $issue['engine_key'] ?? '']),
        ], $result['issues']);

        return response()->json($result + ['prompt_count' => (int) $promptCount, 'core_prompt_count' => $core]);
    }

    /** @param  array<string, mixed>  $input */
    private function start(AnalysisStore $store, string $kind, array $input): JsonResponse
    {
        $tenantId = (int) app(TenantContext::class)->require();
        $analysis = $store->create($tenantId, (int) auth()->id(), $kind, $input);

        RunOnboardingAnalysis::dispatch($tenantId, $analysis->id);

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

    private function quotaAllows(Team $tenant): bool
    {
        $usage = app(MonthlyUsage::class)->forAdmission($tenant, CarbonImmutable::now('UTC'));
        $planned = new PlannedRun(0, true, BillingAccount::Platform, (string) config('ekko.anthropic.onboarding.estimated_cost_usd'));

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
