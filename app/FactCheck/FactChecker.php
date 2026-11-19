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
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 * reservation; an error about the account or the service stops the run and the answer waits, an error about the
 * request itself marks that answer judged (it would fail again) and the run goes on. An unknown outcome (timeout,
 * broken connection) keeps the reservation and marks the answer judged, so it is never billed twice.
 *
 * One run per tenant at a time, by a Postgres advisory lock held for the run: two jobs would otherwise pick the same
 * answers and pay twice.
 */
final class FactChecker
{
    public const DONE = 'done';

    public const MORE = 'more';

    public const BUSY = 'busy';

    /** First key of the session-level advisory lock; the second is the tenant id. */
    private const LOCK_NAMESPACE = 20261119;

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
     * (Unix time). MORE when more answers wait and another run can go on, BUSY when another run holds the tenant.
     *
     * @return self::DONE|self::MORE|self::BUSY
     */
    public function run(int $tenantId, int $deadline): string
    {
        if (! $this->available() || ! Team::query()->findOrFail($tenantId)->mayStartProjectWorkAt(CarbonImmutable::now('UTC'))) {
            return self::DONE;
        }
        if (! DB::selectOne('select pg_try_advisory_lock(?, ?) as locked', [self::LOCK_NAMESPACE, $tenantId])->locked) {
            return self::BUSY;
        }

        try {
            return $this->judgeWaiting($tenantId, $deadline);
        } finally {
            DB::select('select pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, $tenantId]);
        }
    }

    /** @return self::DONE|self::MORE */
    private function judgeWaiting(int $tenantId, int $deadline): string
    {
        $limit = max(1, (int) config('ekko.facts.max_checks_per_run'));
        $pending = $this->rls->run([$tenantId], fn (): array => $this->pending($limit + 1));
        $more = count($pending) > $limit;

        foreach (array_slice($pending, 0, $limit) as $item) {
            if (CarbonImmutable::now()->getTimestamp() >= $deadline) {
                return self::MORE;
            }
            $project = Team::query()->findOrFail($tenantId);
            if (! $project->mayStartProjectWorkAt(CarbonImmutable::now('UTC'))) {
                return self::DONE;
            }
            $outcome = $this->call($tenantId, $item['request']);
            if ($outcome === 'stop') {
                return self::DONE;
            }
            $this->store($tenantId, $item, $outcome instanceof FactJudgeResult ? $outcome : null);
            if ($outcome === 'unknown') {
                // The outcome of the call is unknown (timeout, 5xx, 529): the service may be down, and each further
                // call could be billed without a trace. This answer is marked judged; the rest wait for the next run.
                return self::DONE;
            }
        }

        return $more ? self::MORE : self::DONE;
    }

    /**
     * @return list<array{id: int, brand_id: int, text: string, facts: array<int, string>, request: FactCheckRequest}>
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
                // The facts as they were when the request was built: a verdict only holds for this text.
                'facts' => array_column($facts->factsFor($response), 'text', 'id'),
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
     * error, credit gone); null for an error about this request only.
     */
    private function call(int $tenantId, FactCheckRequest $request): FactJudgeResult|string|null
    {
        $configured = app(Account::class)->configured();
        $operation = 'factcheck:'.Str::uuid()->toString();
        if ($configured && ! Account::independent()->reserve($operation, Money::units((string) config('ekko.anthropic.factcheck.estimated_cost_usd')), false)) {
            return 'stop';
        }

        $project = Team::query()->findOrFail($tenantId);
        if (! $project->mayStartProjectWorkAt(CarbonImmutable::now('UTC'))) {
            if ($configured) {
                Account::independent()->settle($operation);
            }

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

            // An error about the account or the service stops the run and the answer waits. An error about this
            // request only would repeat for it, so that answer is marked judged and the run goes on.
            if (! $failure->known) {
                return 'unknown';
            }

            return $failure->accountWide ? 'stop' : null;
        }

        if ($configured) {
            // Durable before the tenant's booking: a rollback there cannot release a known provider cost.
            Account::independent()->settleKnown($operation, $this->cost->of($result->inputTokens, $result->outputTokens));
        }
        $result->operation = $operation;
        $result->projectKind = $project->kind;

        return $result;
    }

    /**
     * Stores the claims and marks the answer judged, with the usage of a billed call, in one transaction. Without a
     * result (unknown outcome) the answer is only marked judged.
     *
     * @param  array{id: int, brand_id: int, text: string, facts: array<int, string>, request: FactCheckRequest}  $item
     */
    private function store(int $tenantId, array $item, ?FactJudgeResult $result): void
    {
        $claims = [];
        if ($result !== null) {
            $parsed = ClaimParser::parse($result->text);
            if ($parsed === null) {
                Log::warning('Fact check returned no usable result.', ['tenant_id' => $tenantId, 'failure' => $result->failure]);
            }
            $claims = $this->assembler->assemble($item['text'], $parsed ?? [], array_keys($item['facts']));
        }

        $this->rls->run([$tenantId], function () use ($item, $result, $claims): void {
            if ($result !== null) {
                UsageEvent::recordForProject([
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
                ], $result->projectKind ?? throw new \LogicException('Missing project type for fact check'));
            }
            // A fact removed, switched off or reworded while the call ran gets no claim: the verdict was given on the
            // text in the request. The rows are locked, so an edit cannot slip in between this check and the insert
            // (BrandFactManager locks the fact the same way before it deletes the claims).
            $current = BrandFact::query()->where('brand_id', $item['brand_id'])->where('is_active', true)->lockForUpdate()->pluck('text', 'id')->all();
            $this->storeClaims($item['id'], $item['brand_id'], array_values(array_filter(
                $claims,
                fn (ModelClaim $claim): bool => isset($current[$claim->factId], $item['facts'][$claim->factId]) && $current[$claim->factId] === $item['facts'][$claim->factId],
            )));
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
