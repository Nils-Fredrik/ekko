<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetTenantContext;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\OnboardingRunner;
use Throwable;

/**
 * Fetches the site and asks Claude Sonnet 5.5 for the wizard's proposal, or for prompts for a new market
 * (docs/spec.md 13, ADR 0018). The result goes to the analysis store and nowhere else.
 *
 * Runs once (tries = 1): a repeat would bill the model call twice, and the user can start a new analysis.
 * Like CollectEngineRun it has no job-wide RLS transaction, because the site fetch and the model call
 * take tens of seconds; the runner opens a short RLS transaction for each database step.
 */
final class RunOnboardingAnalysis extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(int $tenantId, public readonly string $analysisId)
    {
        parent::__construct($tenantId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(AnalysisStore $store, OnboardingRunner $runner): void
    {
        $analysis = $store->find($this->tenantId, $this->analysisId);
        if ($analysis === null || $analysis->isFinished()) {
            return;
        }

        if ($analysis->kind === Analysis::BRAND) {
            $runner->brand($analysis);
        } else {
            $runner->market($analysis);
        }
    }

    public function failed(Throwable $exception): void
    {
        $store = app(AnalysisStore::class);
        $analysis = $store->find($this->tenantId, $this->analysisId);
        if ($analysis !== null && ! $analysis->isFinished()) {
            app(OnboardingRunner::class)->failUnexpectedly($analysis, $exception);
        }
    }
}
