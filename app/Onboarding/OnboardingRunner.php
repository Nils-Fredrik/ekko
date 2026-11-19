<?php

namespace App\Onboarding;

use App\Models\Brand;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Models\UsageEvent;
use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\SitePage;
use App\Onboarding\Fetch\SiteReader;
use App\Onboarding\Model\OnboardingModelClient;
use App\Onboarding\Model\OnboardingModelFailed;
use App\Onboarding\Model\OnboardingModelResult;
use App\Onboarding\Model\OnboardingPromptBuilder;
use App\Onboarding\Model\OnboardingRequest;
use App\Onboarding\Model\ProposalParser;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one wizard analysis (docs/spec.md 13, ADR 0018) and leaves the result in the {@see AnalysisStore}.
 * Called by the queued jobs, inside the job's tenant context. Nothing is written to the tenant's
 * configuration: the only database write is the usage event for the model call.
 *
 * The job holds no database transaction while it waits for the site and for the model, so every database
 * step here opens its own short RLS transaction.
 */
final class OnboardingRunner
{
    public function __construct(
        private readonly SiteReader $reader,
        private readonly OnboardingPromptBuilder $prompts,
        private readonly ProposalParser $parser,
        private readonly AnalysisStore $store,
        private readonly OnboardingCost $cost,
        private readonly TenantRls $rls,
    ) {}

    public function brand(Analysis $analysis): void
    {
        /** @var array{name: string, primary_domain: string, country: string, language: string} $input */
        $input = $analysis->input;

        $this->advance($analysis, Analysis::FETCHING);
        try {
            $pages = $this->reader->read($input['primary_domain']);
        } catch (FetchFailed $e) {
            $this->fail($analysis, 'fetch_'.$e->reason);

            return;
        }
        $analysis->pages = array_map(fn (SitePage $page): string => $page->url, $pages);

        $this->advance($analysis, Analysis::ANALYSING);
        $pitch = Team::query()->whereKey($analysis->tenantId)->value('kind') === 'pitch';
        $request = $this->prompts->brand($input['name'], $input['primary_domain'], $input['country'], $input['language'], $pages, $pitch);

        $this->finish($analysis, $request, function (string $json) use ($input, $pitch): ?array {
            $result = $this->parser->parseBrand($json, $input['name'], $input['primary_domain']);
            if ($pitch && $result !== null) {
                $result['proposal']['prompts'] = array_slice($result['proposal']['prompts'], 0, 25);
                $result['proposal']['competitors'] = array_slice($result['proposal']['competitors'], 0, 3);
            }

            return $result;
        });
    }

    public function market(Analysis $analysis): void
    {
        /** @var array{brand_id: int, country: string, language: string} $input */
        $input = $analysis->input;

        $this->advance($analysis, Analysis::ANALYSING);

        $context = $this->rls->run([$analysis->tenantId], fn (): ?array => $this->marketContext($analysis->tenantId, $input['brand_id'], $input['language']));
        if ($context === null) {
            $this->fail($analysis, 'brand_gone');

            return;
        }
        if ($context['topics'] === []) {
            $this->fail($analysis, 'no_topics');

            return;
        }

        $keys = array_column($context['topics'], 'cross_market_key');
        $request = $this->prompts->market($context['brand'], $context['domain'], $input['country'], $input['language'], $context['topics'], $context['prompt_count']);

        $this->finish($analysis, $request, fn (string $json): ?array => $this->parser->parseMarket($json, $keys, $context['prompt_count']));
    }

    /**
     * The one failure that is not caught here is a bug: it is left to the job, which marks the analysis failed
     * and lets the queue log it. Provider failures are final (no retry, so one request is never billed twice).
     *
     * @param  callable(string): ?array{proposal: array<string, mixed>, warnings: list<string>}  $parse
     */
    private function finish(Analysis $analysis, OnboardingRequest $request, callable $parse): void
    {
        try {
            // Resolved here, not injected: without ANTHROPIC_API_KEY the container cannot build the client.
            $result = app(OnboardingModelClient::class)->suggest($request);
        } catch (OnboardingModelFailed $e) {
            Log::warning('Onboarding model call failed.', ['analysis' => $analysis->id, 'error' => $e::class]);
            $this->fail($analysis, 'model_unavailable');

            return;
        }

        $this->logUsage($analysis, $result);

        if ($result->failure !== null) {
            $this->fail($analysis, $result->failure === 'refusal' ? 'model_refused' : 'model_truncated');

            return;
        }

        $parsed = $parse((string) $result->text);
        if ($parsed === null) {
            $this->fail($analysis, 'invalid_model_output');

            return;
        }

        $analysis->proposal = $parsed['proposal'];
        $analysis->warnings = $parsed['warnings'];
        $this->advance($analysis, Analysis::READY);
    }

    /**
     * What the new-market request needs: the brand and its topics that can be linked across markets, each with a
     * few example prompts, and how many prompts a market may have under the plan.
     *
     * @return array{brand: string, domain: string, topics: list<array{cross_market_key: string, name: string, examples: list<string>}>, prompt_count: int}|null
     */
    private function marketContext(int $tenantId, int $brandId, string $targetLanguage): ?array
    {
        $brand = Brand::query()->find($brandId);
        if ($brand === null) {
            return null;
        }

        $topics = [];
        foreach (Topic::query()->where('brand_id', $brand->id)->whereNotNull('cross_market_key')->orderBy('id')->limit((int) config('ekko.wizard.suggestions.max_topics') + 4)->get() as $topic) {
            $examples = Prompt::query()
                ->where('topic_id', $topic->id)
                ->where('is_active', true)
                ->whereHas('market', fn ($query) => $query->where('language', '!=', $targetLanguage))
                ->orderByRaw("priority = 'core' DESC")
                ->orderBy('id')
                ->limit(3)
                ->pluck('text')
                ->map(fn (string $text): string => mb_substr($text, 0, 300))
                ->all();

            // A key can only be used once; the schema takes it as an enum.
            $topics[(string) $topic->cross_market_key] ??= ['cross_market_key' => (string) $topic->cross_market_key, 'name' => $topic->name, 'examples' => array_values($examples)];
        }

        $tenant = Team::query()->find($tenantId);
        $plan = $tenant?->plan()->first();
        $max = (int) config('ekko.wizard.suggestions.max_prompts');

        return [
            'brand' => $brand->name,
            'domain' => (string) $brand->primary_domain,
            'topics' => array_values($topics),
            'prompt_count' => $plan instanceof Plan && $plan->max_prompts_per_market !== null ? min($max, $plan->max_prompts_per_market) : $max,
        ];
    }

    private function logUsage(Analysis $analysis, OnboardingModelResult $result): void
    {
        // Billed whether or not the reply was usable (docs/spec.md 12.2). Platform key, so billed to the platform.
        $this->rls->run([$analysis->tenantId], fn () => UsageEvent::query()->create([
            'kind' => 'onboarding',
            'engine_key' => null,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'calls' => 1,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'search_calls' => 0,
            'cost_usd' => $this->cost->of($result->inputTokens, $result->outputTokens),
            'billed_to' => 'platform',
        ]));
    }

    /**
     * Moves the analysis on, unless it has been marked failed in the meantime (the job's failed() after a timeout):
     * a failed analysis stays failed, so a client that saw the failure is never shown a proposal afterwards.
     */
    private function advance(Analysis $analysis, string $status): void
    {
        $stored = $this->store->find($analysis->tenantId, $analysis->id);
        if ($stored !== null && $stored->status === Analysis::FAILED) {
            return;
        }

        $analysis->status = $status;
        $this->store->save($analysis);
    }

    public function fail(Analysis $analysis, string $error): void
    {
        $analysis->status = Analysis::FAILED;
        $analysis->error = $error;
        $analysis->proposal = null;
        $this->store->save($analysis);
    }

    /** For the job's failed(): marks an analysis failed after an unexpected error, without leaking the error itself. */
    public function failUnexpectedly(Analysis $analysis, Throwable $e): void
    {
        Log::error('Onboarding analysis failed.', ['analysis' => $analysis->id, 'error' => $e::class]);
        $this->fail($analysis, 'internal');
    }
}
