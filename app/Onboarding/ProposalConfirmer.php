<?php

namespace App\Onboarding;

use App\Brands\MarketLibrary;
use App\FactCheck\BrandFactManager;
use App\Integrations\BrandIntegrations;
use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\StandardPlan;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Onboarding\Fetch\SiteUrl;
use App\Onboarding\Model\OnboardingSchema;
use App\Onboarding\Model\ProposalParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves a confirmed wizard proposal in one transaction (docs/spec.md 13, ADR 0018). Nothing is written
 * before this step. A brand proposal creates the brand, aliases, domain, competitors, market, topics
 * prompts and facts. A market proposal adds a market and prompts to the existing brand.
 */
final class ProposalConfirmer
{
    public function __construct(
        private ProposalParser $personalData,
        private FirstRun $firstRun,
        private MeasurementPlans $plans,
        private AnalysisStore $store,
        private BrandIntegrations $integrations,
        private BrandFactManager $facts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{brand: Brand, market: Market, runs: int}
     */
    public function confirm(Team $tenant, Analysis $analysis, array $data): array
    {
        return DB::transaction(function () use ($tenant, $analysis, $data): array {
            // Serialize both retries and different analyses competing for the last plan slot.
            $tenant = Team::query()->lock('for no key update')->findOrFail($tenant->id);
            $analysis = $this->store->find($tenant->id, $analysis->id);
            if ($analysis === null || $analysis->status !== Analysis::READY) {
                throw ValidationException::withMessages(['analysis' => __('onboarding.errors.not_ready')]);
            }
            foreach (['country', 'language'] as $field) {
                if (($data[$field] ?? null) !== ($analysis->input[$field] ?? null)) {
                    throw ValidationException::withMessages([$field => __('onboarding.errors.market_changed')]);
                }
            }
            $plan = $tenant->plan()->first();
            if ($tenant->kind === 'pitch') {
                if (! $tenant->mayCollectAt(CarbonImmutable::now('UTC'))) {
                    throw ValidationException::withMessages(['analysis' => __('pitch.expired')]);
                }
                if (count($data['competitors'] ?? []) > 3 || count($data['prompts'] ?? []) > 25) {
                    throw ValidationException::withMessages(['prompts' => __('pitch.limits')]);
                }
                $data['engines'] = StandardPlan::pitchWizardRules($plan);
                unset($data['plan_mode']);
            }
            $brand = $analysis->kind === Analysis::MARKET
                ? $this->existingBrand($analysis)
                : $this->createBrand($plan, $data);
            MarketLibrary::ensureAvailable($brand, $data['country'], $data['language'], $plan?->max_markets_per_brand);
            $market = $brand->markets()->create([
                'country' => strtoupper((string) $data['country']),
                'language' => (string) $data['language'],
                'is_active' => true,
            ]);
            $topics = $analysis->kind === Analysis::MARKET
                ? $this->existingTopics($brand)
                : $this->createTopics($brand, $data['topics'] ?? []);
            $this->createPrompts($market, $topics, $data['prompts'] ?? [], $plan);
            if ($analysis->kind === Analysis::BRAND) {
                $this->createFacts($brand, $data['facts'] ?? []);
            }
            $this->applyPlan($tenant, $analysis, $brand, $market, $data);
            $this->integrations->apply($brand, $data);

            $runs = $this->firstRun->start($tenant, $market);
            $this->store->markConfirmed($analysis);

            return ['brand' => $brand, 'market' => $market, 'runs' => $runs];
        });
    }

    /** @param  array<string, mixed>  $data */
    private function createBrand(?Plan $plan, array $data): Brand
    {
        if ($plan instanceof Plan && $plan->max_brands !== null && Brand::query()->unarchived()->count() >= $plan->max_brands) {
            throw ValidationException::withMessages(['name' => __('onboarding.errors.brand_limit')]);
        }

        $name = trim((string) $data['name']);
        $domain = SiteUrl::hostFromUserInput((string) $data['primary_domain']);
        if ($domain === null) {
            throw ValidationException::withMessages(['primary_domain' => __('onboarding.invalid_domain')]);
        }

        $brand = Brand::query()->create([
            'name' => $name,
            'slug' => $this->slug($name),
            'primary_domain' => $domain,
            'status' => 'active',
        ]);
        $this->alias($brand->aliases(), $name);
        foreach ($data['name_variants'] ?? [] as $variant) {
            if (is_string($variant)) {
                $this->alias($brand->aliases(), $variant);
            }
        }
        $brand->domains()->create(['domain' => $domain, 'include_subdomains' => true]);

        foreach ($data['competitors'] ?? [] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $competitorName = trim((string) ($row['name'] ?? ''));
            $competitorDomain = SiteUrl::hostFromUserInput((string) ($row['domain'] ?? ''));
            if ($competitorName === '' || $competitorDomain === null || $competitorDomain === $domain) {
                throw ValidationException::withMessages([
                    "competitors.{$index}.domain" => __('onboarding.invalid_domain'),
                ]);
            }
            $competitor = $brand->competitors()->create(['name' => $competitorName, 'is_active' => true]);
            $this->alias($competitor->aliases(), $competitorName);
            $competitor->domains()->create(['domain' => $competitorDomain]);
        }

        return $brand;
    }

    private function existingBrand(Analysis $analysis): Brand
    {
        $brand = Brand::query()->unarchived()->find($analysis->input['brand_id'] ?? null);
        if (! $brand instanceof Brand) {
            throw ValidationException::withMessages(['analysis' => __('onboarding.errors.brand_gone')]);
        }

        return $brand;
    }

    /**
     * @param  list<mixed>  $rows
     * @return array<string, Topic>
     */
    private function createTopics(Brand $brand, array $rows): array
    {
        $topics = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $key = $this->key((string) ($row['cross_market_key'] ?? ''));
            if ($name === '' || $key === null || isset($topics[$key])) {
                throw ValidationException::withMessages(["topics.{$index}.name" => __('onboarding.errors.invalid_topic')]);
            }
            $topics[$key] = $brand->topics()->create(['name' => $name, 'cross_market_key' => $key]);
        }

        return $topics;
    }

    /** @return array<string, Topic> */
    private function existingTopics(Brand $brand): array
    {
        $topics = [];
        foreach ($brand->topics()->whereNotNull('cross_market_key')->get() as $topic) {
            $topics[(string) $topic->cross_market_key] = $topic;
        }

        return $topics;
    }

    /**
     * @param  array<string, Topic>  $topics
     * @param  list<mixed>  $rows
     */
    private function createPrompts(Market $market, array $topics, array $rows, ?Plan $plan): void
    {
        $max = $plan?->max_prompts_per_market;
        if ($max !== null && count($rows) > $max) {
            throw ValidationException::withMessages(['prompts' => __('onboarding.errors.prompt_limit')]);
        }

        $maxCore = (int) config('ekko.wizard.suggestions.max_core_prompts');
        if (count(array_filter($rows, fn ($row) => is_array($row) && ($row['priority'] ?? 'standard') === 'core')) > $maxCore) {
            throw ValidationException::withMessages(['prompts' => __('onboarding.errors.core_limit', ['max' => $maxCore])]);
        }
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["prompts.{$index}.text" => __('onboarding.errors.invalid_prompt')]);
            }
            $text = trim((string) ($row['text'] ?? ''));
            $inputKey = (string) ($row['cross_market_key'] ?? '');
            $key = isset($topics[$inputKey]) ? $inputKey : ($this->key($inputKey) ?? '');
            $intent = $row['intent'] ?? null;
            $stage = $row['funnel_stage'] ?? null;
            if ($text === '' || str_contains($text, "\0") || mb_strlen($text) > Prompt::TEXT_MAX || ! isset($topics[$key])
                || ! in_array($intent, OnboardingSchema::INTENTS, true)
                || ! in_array($stage, OnboardingSchema::FUNNEL_STAGES, true)
                || $this->personalData->hasPersonalData($text)) {
                throw ValidationException::withMessages(["prompts.{$index}.text" => __('onboarding.errors.invalid_prompt')]);
            }
            $hash = hash('sha256', $text);
            if (isset($seen[$hash])) {
                throw ValidationException::withMessages(["prompts.{$index}.text" => __('onboarding.errors.duplicate_prompt')]);
            }
            $seen[$hash] = true;
            $priority = ($row['priority'] ?? 'standard') === 'core' ? 'core' : 'standard';
            Prompt::query()->create([
                'market_id' => $market->id,
                'topic_id' => $topics[$key]->id,
                'text' => $text,
                'intent' => $intent,
                'funnel_stage' => $stage,
                'priority' => $priority,
                'is_active' => true,
                'text_hash' => $hash,
            ]);
        }
    }

    /**
     * The facts the user approved (docs/spec.md 11.6): saved active, so the fact check starts with the first answers.
     *
     * @param  list<mixed>  $rows
     */
    private function createFacts(Brand $brand, array $rows): void
    {
        foreach ($rows as $index => $text) {
            try {
                if (! is_string($text)) {
                    throw ValidationException::withMessages(['text' => __('fact_check.errors.empty')]);
                }
                $this->facts->add($brand, $text);
            } catch (ValidationException $e) {
                // A duplicate or empty entry in the list is the user's list to fix, shown on the entry.
                throw ValidationException::withMessages(["facts.{$index}" => $e->errors()['text'] ?? [__('fact_check.errors.empty')]]);
            }
        }
    }

    /**
     * The wizard uses the same measurement plan as brand settings (docs/spec.md 7.6, ADR 0042). A new brand gets the
     * chosen engines as its standard plan, or the package standard when none are sent, and its first market inherits.
     * A new market inherits unless it is sent with plan_mode own (or, from the earlier wizard, with engines only).
     *
     * @param  array<string, mixed>  $data
     */
    private function applyPlan(Team $tenant, Analysis $analysis, Brand $brand, Market $market, array $data): void
    {
        $errors = [];
        $rules = array_key_exists('engines', $data)
            ? $this->plans->validateRules($tenant, array_values((array) $data['engines']), 'engines', $errors)
            : null;
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($analysis->kind === Analysis::BRAND) {
            $this->plans->initialize($brand, $rules ?? StandardPlan::forTenant($tenant));
            $this->plans->inherit($market);

            return;
        }
        $mode = $data['plan_mode'] ?? ($rules === null ? Market::PLAN_INHERIT : Market::PLAN_OWN);
        if ($mode === Market::PLAN_INHERIT) {
            if ($rules !== null) {
                throw ValidationException::withMessages(['engines' => __('app.plan.engines_with_inherit')]);
            }
            if ($this->plans->standardErrors($tenant, $brand) !== []) {
                throw ValidationException::withMessages(['plan_mode' => __('app.plan.standard_invalid')]);
            }
            $this->plans->inherit($market);

            return;
        }
        // Own choices start from a copy of the setup the market would otherwise have: the brand's standard.
        if ($rules === null && $this->plans->standardErrors($tenant, $brand) !== []) {
            throw ValidationException::withMessages(['plan_mode' => __('app.plan.standard_invalid')]);
        }
        $this->plans->own($market, $rules ?? $this->plans->present($tenant, $brand)['standard']);
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'brand';
        }
        $slug = $base;
        $suffix = 2;
        while (Brand::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function key(string $value): ?string
    {
        $key = Str::slug($value, '_');

        return $key === '' ? null : mb_substr($key, 0, 100);
    }

    /** @param  HasMany<BrandAlias, Brand>|HasMany<CompetitorAlias, Competitor>  $relation */
    private function alias(HasMany $relation, string $name): void
    {
        $alias = trim($name);
        if ($alias === '') {
            return;
        }
        $exists = $relation->whereRaw('lower(alias) = ?', [mb_strtolower($alias)])->exists();
        if (! $exists) {
            $relation->create(['alias' => $alias, 'match_mode' => 'case_insensitive']);
        }
    }
}
