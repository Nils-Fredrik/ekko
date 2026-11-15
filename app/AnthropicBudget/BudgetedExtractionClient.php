<?php

namespace App\AnthropicBudget;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionOutcome;
use App\Extraction\ExtractionRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BudgetedExtractionClient implements ExtractionClient
{
    public function __construct(private ExtractionClient $inner, private Account $account, private Estimator $estimator) {}

    public function createBatch(array $requests): string
    {
        DB::transaction(function () use ($requests): void {
            foreach ($requests as $request) {
                $this->reserve($request, true);
            }
        });
        try {
            return $this->inner->createBatch($requests);
        } catch (APIStatusException $failure) {
            $this->checkFailure($failure);
            throw $failure;
        }
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->inner->batchEnded($batchId);
    }

    public function batchResults(string $batchId): iterable
    {
        return $this->inner->batchResults($batchId);
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        $this->reserve($request, false);
        try {
            return $this->inner->message($request);
        } catch (APIStatusException $failure) {
            $this->checkFailure($failure);
            throw $failure;
        }
    }

    private function reserve(ExtractionRequest $request, bool $batch): void
    {
        if (! $this->account->reserve('extract:'.$request->customId, $this->estimator->extraction(strlen($request->system.$request->user), $batch), true)) {
            throw new RuntimeException('anthropic_budget_unavailable');
        }
    }

    private function checkFailure(APIStatusException $failure): void
    {
        if (str_contains(strtolower($failure->getAPIMessage() ?? ''), 'credit balance is too low')) {
            $this->account->markExhausted();
            throw new CreditExhausted;
        }
    }
}
