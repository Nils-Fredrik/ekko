<?php

namespace App\AnthropicBudget;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionCost;
use App\Extraction\ExtractionOutcome;
use App\Extraction\ExtractionRequest;
use Illuminate\Support\Str;
use RuntimeException;

final class BudgetedExtractionClient implements ExtractionClient
{
    public function __construct(private ExtractionClient $inner, private Account $account, private Estimator $estimator) {}

    public function createBatch(array $requests): string
    {
        if (! $this->account->configured()) {
            return $this->inner->createBatch($requests);
        }
        $account = Account::independent();
        $operations = [];
        foreach ($requests as $request) {
            $operation = 'extract:'.Str::uuid();
            if (! $account->reserve($operation, $this->estimator->extraction(strlen($request->system.$request->user)), true)) {
                foreach ($operations as $reserved) {
                    $account->settle($reserved);
                }
                throw new RuntimeException('anthropic_budget_unavailable');
            }
            $operations[$request->customId] = $operation;
        }
        try {
            $batch = $this->inner->createBatch($requests);
        } catch (APIStatusException $failure) {
            foreach ($operations as $operation) {
                $account->settle($operation);
            }
            $this->checkFailure($failure, $account);
            throw $failure;
        }
        foreach ($operations as $customId => $operation) {
            $account->db()->table('anthropic_budget_holds')->where('operation', $operation)->update(['provider_batch_id' => $batch, 'custom_id' => $customId]);
        }

        return $batch;
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->inner->batchEnded($batchId);
    }

    public function batchResults(string $batchId): iterable
    {
        foreach ($this->inner->batchResults($batchId) as $outcome) {
            $operation = $this->account->configured() ? Account::independent()->db()->table('anthropic_budget_holds')->where('provider_batch_id', $batchId)->where('custom_id', $outcome->customId)->value('operation') : null;
            // Settle every known outcome, including a response removed from the tenant job meanwhile.
            yield $operation === null ? $outcome : $this->known($outcome, $operation);
        }
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        if (! $this->account->configured()) {
            return $this->inner->message($request);
        }
        $account = Account::independent();
        $operation = 'extract:'.Str::uuid();
        if (! $account->reserve($operation, $this->estimator->extraction(strlen($request->system.$request->user), false), true)) {
            throw new RuntimeException('anthropic_budget_unavailable');
        }
        try {
            return $this->known($this->inner->message($request), $operation);
        } catch (APIStatusException $failure) {
            $account->settle($operation);
            $this->checkFailure($failure, $account);
            throw $failure;
        }
    }

    private function known(ExtractionOutcome $outcome, string $operation): ExtractionOutcome
    {
        Account::independent()->settleKnown($operation, app(ExtractionCost::class)->of($outcome));

        return new ExtractionOutcome($outcome->customId, $outcome->succeeded, $outcome->text, $outcome->inputTokens, $outcome->outputTokens, $outcome->viaBatch, $outcome->errorType, $operation);
    }

    private function checkFailure(APIStatusException $failure, Account $account): void
    {
        if (str_contains(strtolower($failure->getAPIMessage() ?? ''), 'credit balance is too low')) {
            $account->markExhausted();
            throw new CreditExhausted;
        }
    }
}
