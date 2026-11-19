<?php

namespace App\ActionEngine;

use App\ActionEngine\Writing\ActionText;
use App\ActionEngine\Writing\ActionTextRequest;
use App\ActionEngine\Writing\ActionWriter;
use App\ActionEngine\Writing\ActionWriterFailed;
use App\ActionEngine\Writing\AnthropicActionWriter;
use App\AnthropicBudget\Account;
use App\AnthropicBudget\Money;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One paid wording call (docs/spec.md 11.3, ADR 0034), outside any tenant transaction. Before the call the
 * estimated cost is reserved on the Anthropic account (ADR 0023); it is refused in reduced mode, and then the
 * action keeps its template text. A known reply is settled and booked in the tenant's usage_events (kind actions,
 * billed to the platform) whether or not the text is usable. The platform pays for it: it is not counted in the
 * tenant's quota (App\Usage\PlatformPaidUsage). A known provider error releases the reservation; an unknown
 * outcome keeps it, and nothing is retried, so a call is never billed twice. Each attempt writes one log line with
 * internal IDs, the outcome, tokens and cost, never texts.
 */
final readonly class ActionFormulator
{
    public function __construct(
        private TenantRls $rls,
        private ActionTextGuard $guard,
        private ActionCost $cost,
    ) {}

    public function available(): bool
    {
        return AnthropicActionWriter::configured();
    }

    public function formulate(int $tenantId, int $actionId, ActionTextRequest $request): ?ActionText
    {
        if (! $this->available()) {
            return null;
        }

        $configured = app(Account::class)->configured();
        $operation = 'actions:'.Str::uuid()->toString();
        $log = ['tenant' => $tenantId, 'action' => $actionId, 'operation' => $operation];
        if ($configured && ! Account::independent()->reserve($operation, Money::units((string) config('ekko.anthropic.actions.estimated_cost_usd')), false)) {
            Log::info('Action wording call.', $log + ['outcome' => 'not_reserved']);

            return null;
        }

        try {
            // Resolved here: without ANTHROPIC_API_KEY the container cannot build the client.
            $result = app(ActionWriter::class)->write($request);
        } catch (ActionWriterFailed $failure) {
            // The message is an exception class or a fixed code, never provider text.
            Log::warning('Action wording call.', $log + ['outcome' => $failure->known ? 'failed' : 'unknown', 'error' => $failure->getMessage(), 'credit_exhausted' => $failure->creditExhausted]);
            if ($configured && $failure->known) {
                Account::independent()->settle($operation);
            }
            if ($failure->creditExhausted) {
                Account::independent()->markExhausted();
            }

            return null;
        }

        $cost = $this->cost->of($result->inputTokens, $result->outputTokens);
        if ($configured) {
            // Durable before the tenant's booking: a rollback there cannot release a known provider cost.
            Account::independent()->settleKnown($operation, $cost);
        }
        $usage = $this->rls->run([$tenantId], fn () => UsageEvent::query()->create([
            'kind' => 'actions',
            'engine_key' => null,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'calls' => 1,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'search_calls' => 0,
            'cost_usd' => $cost,
            'billed_to' => 'platform',
            'anthropic_operation' => $operation,
        ]));

        $text = $result->text === null ? null : $this->guard->parse($result->text, $request->names);
        Log::info('Action wording call.', $log + [
            'outcome' => $text === null ? 'refused' : 'worded',
            'usage_event' => $usage->id,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => $cost,
        ]);

        return $text;
    }
}
