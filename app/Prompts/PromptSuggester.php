<?php

namespace App\Prompts;

use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\OnboardingRunner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class PromptSuggester
{
    public function __construct(private AnalysisStore $store, private OnboardingRunner $runner) {}

    /** @return list<array<string, mixed>> */
    public function forTopic(Topic $topic, Market $market, int $count): array
    {
        abort_unless($topic->brand_id === $market->brand_id, 404);
        abort_unless(in_array($count, [5, 10], true), 422);
        $tenant = Team::query()->findOrFail(current_tenant_id());
        try {
            $analysis = $this->store->admit($tenant, (int) auth()->id(), 'topic', []);
        } catch (LockTimeoutException) {
            abort(429, __('onboarding.errors.busy'));
        }
        abort_if($analysis === null, 429, __('onboarding.errors.quota_reached'));
        $this->runner->topic($analysis, $topic, $market, $count);
        if ($analysis->status !== Analysis::READY) {
            // A refusal or malformed model output can still incur usage. Return a client error so
            // the request's RLS middleware commits the recorded charge.
            throw ValidationException::withMessages(['suggestions' => __('onboarding.errors.'.($analysis->error ?? 'invalid_model_output'))]);
        }
        $items = $analysis->proposal['prompts'] ?? [];
        $hashes = array_map(fn (array $row): string => Prompt::hashText($row['text']), $items);
        $existing = Prompt::query()->where('market_id', $market->id)->whereIn('text_hash', $hashes)->pluck('text_hash')->flip();
        $suggestions = [];
        foreach ($items as $row) {
            if (! $existing->has(Prompt::hashText($row['text']))) {
                $suggestions[] = Arr::only($row, ['text', 'intent', 'funnel_stage', 'priority']);
            }
        }

        return $suggestions;
    }
}
