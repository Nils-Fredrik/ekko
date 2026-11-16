<?php

namespace App\Onboarding\Model;

/**
 * The one provider call the wizard needs (docs/spec.md 13): a single Messages API request to Claude Sonnet 5.5
 * with structured output. {@see AnthropicOnboardingClient} is the real implementation; tests use a fake and
 * never call the API.
 */
interface OnboardingModelClient
{
    /** @throws OnboardingModelFailed when the provider could not be reached or rejected the request */
    public function suggest(OnboardingRequest $request): OnboardingModelResult;
}
