<?php

namespace Tests\Support\Onboarding;

use App\Onboarding\Model\OnboardingModelClient;
use App\Onboarding\Model\OnboardingModelFailed;
use App\Onboarding\Model\OnboardingModelResult;
use App\Onboarding\Model\OnboardingRequest;

/** A model client that answers with canned text and keeps the requests. It never calls the API. */
final class FakeOnboardingModelClient implements OnboardingModelClient
{
    /** @var list<OnboardingRequest> */
    public array $requests = [];

    /** Runs inside the call, before the reply: lets a test change state while the model is answering. */
    public ?\Closure $before = null;

    public function __construct(
        public ?string $text = null,
        public ?string $failure = null,
        public bool $unavailable = false,
        public int $inputTokens = 4000,
        public int $outputTokens = 3000,
    ) {}

    public function suggest(OnboardingRequest $request): OnboardingModelResult
    {
        $this->requests[] = $request;
        if ($this->before !== null) {
            ($this->before)();
        }
        if ($this->unavailable) {
            throw OnboardingModelFailed::unavailable();
        }

        return new OnboardingModelResult($this->failure === null ? $this->text : null, $this->inputTokens, $this->outputTokens, $this->failure);
    }
}
