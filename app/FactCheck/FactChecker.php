<?php

namespace App\FactCheck;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Money;
use App\Detection\UrlNormalizer;
use App\FactCheck\Judge\AnthropicFactJudge;
use App\FactCheck\Judge\FactJudge;
use App\FactCheck\Judge\FactJudgeFailed;
use App\FactCheck\Judge\FactJudgeResult;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Response;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Judges the answers of a tenant against the facts of their brand (docs/spec.md 11.6, ADR 0036): one model call
 * per extracted answer whose brand has active facts, outside any tenant transaction (a call can take many
 * seconds). Each answer is judged once, against the facts as they are when it is judged.
 *
 * Before a call the estimated cost is reserved on the Anthropic account (ADR 0023), and it is refused in reduced
 * mode. A known reply is settled and booked as the tenant's usage (kind factcheck, billed to the platform) whether or
 * not the text is usable, together with the claims, in one transaction. A known provider error releases the
 * reservation and stops the run, the answer waits. An unknown outcome (timeout, broken connection) keeps the
 * reservation and marks the answer judged, so it is never billed twice.
 */
final class FactChecker
{
    public function __construct(
        private readonly TenantRls $rls,
        private readonly FactCheckPromptBuilder $prompts,
        private readonly ClaimAssembler $assembler,
        private readonly FactCheckCost $cost,
        private readonly UrlNormalizer $urls = new UrlNormalizer,
    ) {}

    public function available(): bool
    {
        return AnthropicFactJudge::configured();
    }

    /**
     * Judges the tenant's waiting answers, at most ekko.facts.max_checks_per_run, and starts no call after $deadline
     * (Unix time). True when more answers wait and another run can go on.
     */
    public function run(int $tenantId, int $deadline): bool
    {
        if (! $this->available()) {
            return false;
        }

        $limit = max(1, (int) config('ekko.facts.max_checks_per_run'));
        $pending = $this->rls->run([$tenantId], fn (): array => $this->pending($limit + 1));
        $more = count($pending) > $limit;

        foreach (array_slice($pending, 0, $limit) as $item) {
            if (CarbonImmutable::now()->getTimestamp() >= $deadline) {
                return true;
            }
            $outcome = $this->call($tenantId, $item['request']);
            if ($outcome === 'stop') {
                return false;
            }
            $this->store($tenantId, $item, $outcome instanceof FactJudgeResult ? $outcome : null);
        }

        return $more;
    }

    /**
     * @return list<array{id: int, brand_id: int, text: string, fact_ids: list<int>, request: FactCheckRequest}>
     */
    private function pending(int $limit): array
    {
        $facts = FactCatalog::load();
        if ($facts->brandIds() === []) {
            return [];
        }

        $responses = Response::query()
            ->whereNull('facts_checked_at')
            ->where('collected_at', '>=', CarbonImmutable::now('UTC')->subDays((int) config('ekko.facts.max_age_days')))
            ->whereRaw("answer_text !~ '^\\s*$'")
            ->whereHas('run', fn ($run) => $run->where('status', 'extracted'))
            ->whereHas('run.prompt.market', fn ($market) => $market->whereIn('brand_id', $facts->brandIds()))
            ->with(['run.prompt.market', 'citations'])
            ->orderBy('id')->limit($limit)->get();

        $items = [];
        foreach ($responses as $response) {
            $items[] = [
                'id' => $response->id,
                'brand_id' => FactCatalog::brandId($response),
                'text' => (string) $response->answer_text,
                'fact_ids' => $facts->factIds($response),
                'request' => $this->prompts->build(
                    'r'.$response->id,
                    (string) $response->answer_text,
                    $response->run->prompt->market->language ?? $response->answer_language ?? 'und',
                    $facts->brandName($response),
                    $facts->factsFor($response),
                    $response->citations->pluck('url')->all(),
                ),
            ];
        }

        return $items;
    }

    /**
     * One paid call. A billed reply is returned; 'stop' when nothing can be called now (reduced mode, a known provider
     * error, credit gone); null for an unknown outcome, whose reservation is kept.
     */
    private function call(int $tenantId, FactCheckRequest $request): FactJudgeResult|string|null
    {
        $configured = app(Account::class)->configured();
        $operation = 'factcheck:'.Str::uuid()->toString();
        if ($configured && ! Account::independent()->reserve($operation, Money::units((string) config('ekko.anthropic.factcheck.estimated_cost_usd')), false)) {
            return 'stop';
        }

        try {
            // Resolved here: without ANTHROPIC_API_KEY the container cannot build the client.
            $result = app(FactJudge::class)->judge($request);
        } catch (FactJudgeFailed $failure) {
            Log::warning('Fact check failed.', ['tenant_id' => $tenantId, 'error' => $failure->getMessage(), 'known' => $failure->known]);
            if ($configured && $failure->known) {
                Account::independent()->settle($operation);
            }
            if ($failure->creditExhausted) {
                Account::independent()->markExhausted();
            }

            return $failure->known ? 'stop' : null;
        }

        if ($configured) {
            // Durable before the tenant's booking: a rollback there cannot release a known provider cost.
            Account::independent()->settleKnown($operation, $this->cost->of($result->inputTokens, $result->outputTokens));
        }
        $result->operation = $operation;

        return $result;
    }

    /**
     * Stores the claims and marks the answer judged, with the usage of a billed call, in one transaction. Without a
     * result (unknown outcome) the answer is only marked judged.
     *
     * @param  array{id: int, brand_id: int, text: string, fact_ids: list<int>, request: FactCheckRequest}  $item
     */
    private function store(int $tenantId, array $item, ?FactJudgeResult $result): void
    {
        $claims = [];
        if ($result !== null) {
            $parsed = ClaimParser::parse($result->text);
            if ($parsed === null) {
                Log::warning('Fact check returned no usable result.', ['tenant_id' => $tenantId, 'failure' => $result->failure]);
            }
            $claims = $this->assembler->assemble($item['text'], $parsed ?? [], $item['fact_ids']);
        }

        $this->rls->run([$tenantId], function () use ($item, $result, $claims): void {
            if ($result !== null) {
                UsageEvent::query()->create([
                    'kind' => 'factcheck',
                    'engine_key' => null,
                    'occurred_at' => CarbonImmutable::now('UTC'),
                    'calls' => 1,
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                    'search_calls' => 0,
                    'cost_usd' => $this->cost->of($result->inputTokens, $result->outputTokens),
                    'billed_to' => 'platform',
                    'anthropic_operation' => $result->operation,
                ]);
            }
            // A fact removed or switched off meanwhile gets no claim (the foreign key would refuse a deleted one).
            $active = BrandFact::query()->where('brand_id', $item['brand_id'])->where('is_active', true)->pluck('id')->all();
            $this->storeClaims($item['id'], $item['brand_id'], array_values(array_filter($claims, fn (ModelClaim $claim): bool => in_array($claim->factId, $active, true))));
            Response::query()->whereKey($item['id'])->update(['facts_checked_at' => CarbonImmutable::now('UTC')]);
        });
    }

    /**
     * Replaces the response's claims. The sources of a contradicted claim are the response's own citations that the
     * model named, matched on the normalized URL.
     *
     * @param  list<ModelClaim>  $claims
     */
    private function storeClaims(int $responseId, int $brandId, array $claims): void
    {
        Claim::query()->where('response_id', $responseId)->delete();
        if ($claims === []) {
            return;
        }

        $citations = Citation::query()->where('response_id', $responseId)->pluck('id', 'url_normalized')->all();
        foreach ($claims as $claim) {
            $sources = [];
            foreach ($claim->sourceUrls as $url) {
                $id = $citations[$this->urls->normalize($url)['url'] ?? ''] ?? null;
                if ($id !== null) {
                    $sources[$id] = (int) $id;
                }
            }

            Claim::query()->create([
                'response_id' => $responseId,
                'brand_id' => $brandId,
                'fact_id' => $claim->factId,
                'text' => $claim->text,
                'quote' => $claim->quote,
                'verdict' => $claim->verdict,
                'source_citation_ids' => array_values($sources),
            ]);
        }
    }
}
