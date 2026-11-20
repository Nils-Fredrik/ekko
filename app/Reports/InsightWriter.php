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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spends one current, unused insight allocation on a short Sonnet paragraph (ADR 0023).
 * The reservation of any cost above the allocation happens before the HTTP call.
 * The paid result is booked and the draft is kept before the PDF is rendered, so a render
 * failure does not spend the allocation again.
 */
final class InsightWriter
{
    /** How long another run may still be writing the insight. Matches the tenant report lock. */
    public const CLAIM_SECONDS = 900;

    public function __construct(
        private readonly Account $account,
        private readonly EnginePricing $pricing,
        private readonly InsightPrice $prices,
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

        // Weekly and monthly schedules can start together when the 1st is a Monday.
        // Claim the row before this transaction ends, so only one of them calls Sonnet.
        $claimed = AnthropicInsightAllocation::query()
            ->whereKey($allocation->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => CarbonImmutable::now('UTC')]);
        if ($claimed !== 1) {
            return null;
        }

        try {
            $price = $this->pricing->effectiveAt('claude_sonnet', CarbonImmutable::now('UTC'));
        } catch (ModelNotFoundException) {
            $this->releaseClaim($allocation->id);

            return null;
        }

        $ceiling = Money::units($this->pricing->cost($price, new EngineResult('', null, [], 3000, 800, 0, [])));
        $held = Money::units((string) $allocation->cost_usd);
        $operation = 'insight:'.$brand->id.':'.$cycle;
        $overage = null;
        if ($ceiling > $held) {
            $overage = $operation.':overage:'.Str::uuid()->toString();
            if (! Account::independent()->reserve($overage, $ceiling - $held, false)) {
                $this->releaseClaim($allocation->id);

                return null;
            }
        }

        return new InsightAdmission($allocation->id, $brand->id, $cycle, $operation, $overage, $price);
    }

    /**
     * True only while another run may still store the text. A booked insight, or a claim older
     * than the tenant lock, is not pending: later schedules render without a second model call.
     */
    public function pending(Brand $brand): bool
    {
        $cycle = $this->account->cycle()->start->toDateString();
        $allocation = AnthropicInsightAllocation::query()
            ->where('brand_id', $brand->id)
            ->whereDate('cycle_start', $cycle)
            ->whereNotNull('consumed_at')
            ->first();
        if (! $allocation instanceof AnthropicInsightAllocation || $allocation->consumed_at === null) {
            return false;
        }
        if (UsageEvent::query()->where('anthropic_operation', 'insight:'.$brand->id.':'.$cycle)->exists()) {
            return false;
        }

        return $allocation->consumed_at->greaterThan(CarbonImmutable::now('UTC')->subSeconds(self::CLAIM_SECONDS));
    }

    public function release(InsightAdmission $admission): void
    {
        if ($admission->overageOperation !== null) {
            Account::independent()->settle($admission->overageOperation);
        }
        $this->releaseClaim($admission->allocationId);
    }

    /** Gives the allocation back when the Sonnet call never happened. */
    private function releaseClaim(int $allocationId): void
    {
        AnthropicInsightAllocation::query()->whereKey($allocationId)->update(['consumed_at' => null]);
    }

    /**
     * @param  array<string, int|float|string|null>  $summary
     */
    public function draft(InsightAdmission $admission, string $brandName, array $summary, string $locale): ?InsightDraft
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
                        'locale' => $locale,
                        'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                    ]),
                ]],
            ]);
        } catch (Throwable $failure) {
            // A timeout can already have been billed. Keep the claim and the reservation.
            throw new InsightPending('The insight call had no known result.', 0, $failure);
        }

        if (! $response->successful()) {
            if (str_contains(strtolower((string) $response->json('error.message')), 'credit balance is too low')) {
                Account::independent()->markExhausted();
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

        return new InsightDraft($admission, $text, $this->prices->quote(
            $admission->price,
            new EngineResult($text, $model, [], $input, $output, 0, []),
        ), $input, $output);
    }

    /** Records the paid call on the tenant. The caller settles the platform account afterwards. */
    public function book(InsightDraft $draft): void
    {
        $this->bookStored($draft->admission->operation, $draft->cost, $draft->admission->allocationId, $draft->inputTokens, $draft->outputTokens);
    }

    /** Books a draft that was stored before the usage row, without a second row for the same call. */
    public function bookStored(string $operation, string $cost, int $allocationId, int $inputTokens, int $outputTokens): void
    {
        DB::transaction(function () use ($operation, $cost, $allocationId, $inputTokens, $outputTokens): void {
            if (! UsageEvent::query()->where('anthropic_operation', $operation)->exists()) {
                $claim = AnthropicInsightAllocation::query()->findOrFail($allocationId);
                UsageEvent::recordAtOrigin([
                    'occurred_at' => CarbonImmutable::now('UTC'),
                    'kind' => 'pdf',
                    'engine_key' => 'claude_sonnet',
                    'calls' => 1,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cost_usd' => $cost,
                    'billed_to' => 'platform',
                    'anthropic_operation' => $operation,
                ], $claim->consumed_at);
            }
            AnthropicInsightAllocation::query()->whereKey($allocationId)->update([
                'consumed_at' => CarbonImmutable::now('UTC'),
            ]);
        });
    }

    /**
     * Writes the paid paragraph so a later run can render the PDF without calling Sonnet again.
     */
    public function remember(string $disk, string $path, InsightDraft $draft): void
    {
        Storage::disk($disk)->put($path, json_encode([
            'text' => $draft->text,
            'cost' => $draft->cost,
            'operation' => $draft->admission->operation,
            'overage' => $draft->admission->overageOperation,
            'input_tokens' => $draft->inputTokens,
            'output_tokens' => $draft->outputTokens,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{text: string, cost: string, operation: string, overage: string|null, input_tokens: int, output_tokens: int}|null
     */
    public function remembered(string $disk, string $path): ?array
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return null;
        }
        $stored = $storage->get($path);
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);
        if (! is_array($decoded) || ! is_string($decoded['text'] ?? null) || ! is_string($decoded['cost'] ?? null) || ! is_string($decoded['operation'] ?? null)) {
            return null;
        }
        $overage = $decoded['overage'] ?? null;

        $input = $decoded['input_tokens'] ?? 0;
        $output = $decoded['output_tokens'] ?? 0;
        if (! is_numeric($input) || ! is_numeric($output)) {
            return null;
        }

        return [
            'text' => $decoded['text'],
            'cost' => $decoded['cost'],
            'operation' => $decoded['operation'],
            'overage' => is_string($overage) ? $overage : null,
            'input_tokens' => (int) $input,
            'output_tokens' => (int) $output,
        ];
    }

    public function settleBooked(string $operation, string $cost, ?string $overage): void
    {
        $budget = Account::independent();
        if (DB::transactionLevel() > 0) {
            // The usage observer's spend row is still uncommitted, so a second connection
            // must not insert the same key. The hold was reserved on its own connection.
            $this->account->db()->table('anthropic_credit_spend')->insertOrIgnore([
                'entry' => $operation,
                'occurred_at' => CarbonImmutable::now('UTC'),
                'cost_usd' => $cost,
            ]);
            $budget->settle($operation);
        } else {
            $budget->settleKnown($operation, $cost);
        }
        if ($overage !== null) {
            $budget->settle($overage);
        }
    }
}
