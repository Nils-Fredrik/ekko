<?php

namespace App\Reports;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Money;
use App\Engines\EnginePricing;
use App\Engines\EngineResult;
use App\Models\AnthropicInsightAllocation;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spends one current, unused insight allocation on a short Sonnet paragraph (ADR 0023).
 * The reservation of any cost above the allocation happens before the HTTP call.
 * Booking the usage, marking the allocation consumed and settling the holds is one transaction.
 */
final class InsightWriter
{
    public function __construct(
        private readonly Account $account,
        private readonly EnginePricing $pricing,
    ) {}

    public function admit(Brand $brand): ?InsightAdmission
    {
        if (trim((string) config('services.anthropic.key')) === '') {
            return null;
        }
        $cycle = $this->account->cycle()->start->toDateString();
        $allocation = AnthropicInsightAllocation::query()
            ->where('brand_id', $brand->id)
            ->whereDate('cycle_start', $cycle)
            ->whereNull('consumed_at')
            ->first();
        if (! $allocation instanceof AnthropicInsightAllocation || ! Engine::query()->whereKey('claude_sonnet')->exists()) {
            return null;
        }

        try {
            $price = $this->pricing->effectiveAt('claude_sonnet', CarbonImmutable::now('UTC'));
        } catch (ModelNotFoundException) {
            return null;
        }

        $ceiling = Money::units($this->pricing->cost($price, new EngineResult('', null, [], 3000, 800, 0, [])));
        $held = Money::units((string) $allocation->cost_usd);
        $operation = 'insight:'.$brand->id.':'.$cycle;
        $overage = null;
        if ($ceiling > $held) {
            $overage = $operation.':overage:'.Str::uuid()->toString();
            if (! $this->account->reserve($overage, $ceiling - $held, false)) {
                return null;
            }
        }

        return new InsightAdmission($allocation->id, $brand->id, $cycle, $operation, $overage);
    }

    public function release(InsightAdmission $admission): void
    {
        if ($admission->overageOperation !== null) {
            $this->account->settle($admission->overageOperation);
        }
    }

    /**
     * @param  array<string, int|float|string|null>  $summary
     */
    public function draft(InsightAdmission $admission, string $brandName, array $summary): ?InsightDraft
    {
        $apiKey = trim((string) config('services.anthropic.key'));
        if ($apiKey === '') {
            return null;
        }

        $engine = Engine::query()->find('claude_sonnet');
        $model = $engine instanceof Engine ? trim((string) $engine->model_id) : '';
        if ($model === '') {
            $model = 'claude-sonnet-5-5';
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->acceptJson()->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 800,
                'messages' => [[
                    'role' => 'user',
                    'content' => __('reports.insight_prompt', [
                        'brand' => $brandName,
                        'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                    ]),
                ]],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            if (str_contains(strtolower((string) $response->json('error.message')), 'credit balance is too low')) {
                $this->account->markExhausted();
            }

            return null;
        }

        $input = max(0, (int) $response->json('usage.input_tokens'));
        $output = max(0, (int) $response->json('usage.output_tokens'));
        $text = '';
        $content = $response->json('content');
        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $text .= $block['text'];
                }
            }
        }
        $text = trim($text);
        $price = $this->pricing->effectiveAt('claude_sonnet', CarbonImmutable::now('UTC'));
        $cost = $this->pricing->cost($price, new EngineResult($text, $model, [], $input, $output, 0, []));

        return new InsightDraft($admission, $text, $cost);
    }

    public function commit(InsightDraft $draft): void
    {
        DB::transaction(function () use ($draft): void {
            UsageEvent::query()->create([
                'occurred_at' => CarbonImmutable::now('UTC'),
                'kind' => 'pdf',
                'engine_key' => 'claude_sonnet',
                'calls' => 1,
                'cost_usd' => $draft->cost,
                'billed_to' => 'platform',
            ]);
            AnthropicInsightAllocation::query()->whereKey($draft->admission->allocationId)->update([
                'consumed_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->account->settle($draft->admission->operation);
            if ($draft->admission->overageOperation !== null) {
                $this->account->settle($draft->admission->overageOperation);
            }
        });
    }
}
