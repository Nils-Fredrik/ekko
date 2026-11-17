<?php

namespace App\Onboarding;

use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Onboarding\Fetch\SiteUrl;
use App\Onboarding\Model\OnboardingSchema;
use App\Onboarding\Model\ProposalParser;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves a confirmed wizard proposal in one transaction (docs/spec.md 13, ADR 0018). Nothing is written
 * before this step. A brand proposal creates the brand, aliases, domain, competitors, market, topics
 * and prompts. A market proposal adds a market and prompts to the existing brand.
 */
final class ProposalConfirmer
{
    public function __construct(private ProposalParser $personalData, private FirstRun $firstRun, private AnalysisStore $store) {}

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
            $plan = $tenant->plan()->first();
            $brand = $analysis->kind === Analysis::MARKET
                ? $this->existingBrand($analysis)
                : $this->createBrand($plan, $data);
            $this->guardMarketLimit($brand, $plan);
            $market = $brand->markets()->create([
                'country' => strtoupper((string) $data['country']),
                'language' => (string) $data['language'],
                'is_active' => true,
            ]);
            $topics = $analysis->kind === Analysis::MARKET
                ? $this->existingTopics($brand)
                : $this->createTopics($brand, $data['topics'] ?? []);
            $this->createPrompts($market, $topics, $data['prompts'] ?? [], $plan);
            $this->createEngines($market, $data['engines'] ?? [], $plan);

            $runs = $this->firstRun->start($tenant, $market);
            $this->store->markConfirmed($analysis);

            return ['brand' => $brand, 'market' => $market, 'runs' => $runs];
        });
    }

    /** @param  array<string, mixed>  $data */
    private function createBrand(?Plan $plan, array $data): Brand
    {
        if ($plan instanceof Plan && $plan->max_brands !== null && Brand::query()->count() >= $plan->max_brands) {
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
        $brand = Brand::query()->find($analysis->input['brand_id'] ?? null);
        if (! $brand instanceof Brand) {
            throw ValidationException::withMessages(['analysis' => __('onboarding.errors.brand_gone')]);
        }

        return $brand;
    }

    private function guardMarketLimit(Brand $brand, ?Plan $plan): void
    {
        if ($plan instanceof Plan && $plan->max_markets_per_brand !== null && $brand->markets()->count() >= $plan->max_markets_per_brand) {
            throw ValidationException::withMessages(['country' => __('onboarding.errors.market_limit')]);
        }
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
            $key = $this->key((string) ($row['cross_market_key'] ?? '')) ?? '';
            $intent = $row['intent'] ?? null;
            $stage = $row['funnel_stage'] ?? null;
            if ($text === '' || str_contains($text, "\0") || mb_strlen($text) > 10000 || ! isset($topics[$key])
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
     * @param  list<mixed>  $rows
     */
    private function createEngines(Market $market, array $rows, ?Plan $plan): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['engine_key'] ?? '');
            $frequency = (string) ($row['frequency'] ?? '');
            $repetitions = (int) ($row['repetitions'] ?? 1);
            $engine = Engine::query()->find($key);
            if (! $engine instanceof Engine || ! $engine->isAvailable() || isset($seen[$key])
                || ($plan instanceof Plan && ! $plan->allowsEngine($key))
                || ! $this->frequencyAllowed($frequency, $plan instanceof Plan ? $plan->maxFrequencyForEngine($key) : 'daily')
                || $repetitions < 1 || $repetitions > 3
                || ($plan instanceof Plan && $repetitions > $plan->max_repetitions)) {
                throw ValidationException::withMessages([
                    "engines.{$index}.engine_key" => __('onboarding.errors.invalid_engine'),
                ]);
            }
            $seen[$key] = true;
            if ($frequency !== 'off' && ! EnginePrice::query()->where('engine_key', $key)->where('valid_from', '<=', now())->exists()) {
                throw ValidationException::withMessages(["engines.{$index}.engine_key" => __('onboarding.issues.no_price', ['engine' => $key])]);
            }
            $market->marketEngines()->create([
                'engine_key' => $key,
                'frequency' => $frequency,
                'prompt_scope' => 'all',
                'repetitions' => $repetitions,
            ]);
        }
    }

    private function frequencyAllowed(string $chosen, string $max): bool
    {
        $rank = ['off' => 0, 'monthly' => 1, 'weekly' => 2, 'daily' => 3];

        return isset($rank[$chosen], $rank[$max]) && $rank[$chosen] <= $rank[$max];
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
