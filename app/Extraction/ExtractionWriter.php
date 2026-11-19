<?php

namespace App\Extraction;

use App\Detection\DetectedMention;
use App\Detection\DetectionCatalog;
use App\Detection\DeterministicDetector;
use App\Detection\UrlNormalizer;
use App\FactCheck\FactCatalog;
use App\Jobs\CheckTenantFacts;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Mention;
use App\Models\Response;
use App\Models\UsageEvent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMismatchException;
use App\Work\WorkChain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores the combined extraction result for one response (docs/spec.md 8.2, ADR 0011) inside the tenant
 * context of the job. Idempotent: it replaces the response's earlier `llm` mentions and re-applies the
 * enrichment of the deterministic ones, so it must run after {@see DeterministicDetector},
 * which recreates the deterministic rows (ADR 0010).
 */
final class ExtractionWriter
{
    public function __construct(
        private readonly UrlNormalizer $urls = new UrlNormalizer,
        private readonly ExtractionCost $cost = new ExtractionCost,
    ) {}

    /**
     * The deterministic mentions stored for the response, in order of first occurrence.
     *
     * @return list<DetectedMention>
     */
    public function deterministicMentions(Response $response): array
    {
        return Mention::query()
            ->where('response_id', $response->id)->where('detection', 'deterministic')
            ->orderBy('first_char_offset')->orderBy('id')->get()
            ->map(fn (Mention $mention): DetectedMention => new DetectedMention(
                $mention->entity_type, (int) $mention->entity_id, $mention->surface_name,
                (int) $mention->first_char_offset, $mention->list_position,
            ))->values()->all();
    }

    public function write(Response $response, AssembledExtraction $extraction, DetectionCatalog $catalog): void
    {
        $this->guardTenant($response);

        DB::transaction(function () use ($response, $extraction, $catalog): void {
            Mention::query()->where('response_id', $response->id)->where('detection', 'llm')->delete();

            foreach ($extraction->mentions as $mention) {
                if ($mention->detection === 'deterministic') {
                    // By entity, not by row: the deterministic stage writes one mention per entity (ADR 0010).
                    Mention::query()
                        ->where('response_id', $response->id)->where('detection', 'deterministic')
                        ->where('entity_type', $mention->entityType)->where('entity_id', $mention->entityId)
                        ->update([
                            'is_recommended' => $mention->isRecommended,
                            'sentiment' => $mention->sentiment === null ? null : round($mention->sentiment, 3),
                        ]);

                    continue;
                }

                Mention::query()->create([
                    'response_id' => $response->id,
                    'entity_type' => 'unknown',
                    'entity_id' => null,
                    'surface_name' => $mention->surfaceName,
                    'first_char_offset' => $mention->firstCharOffset,
                    'list_position' => $mention->listPosition,
                    'is_recommended' => $mention->isRecommended,
                    'sentiment' => $mention->sentiment === null ? null : round($mention->sentiment, 3),
                    'detection' => 'llm',
                ]);
            }

            if (! $extraction->llmFailed) {
                $this->storeCitations($response, $extraction->citations, $catalog);
            }

            $response->update(['answer_type' => $extraction->answerType, 'llm_failed' => $extraction->llmFailed]);
            $this->markExtracted($response);
        });

        // A brand with facts has its answers judged against them (docs/spec.md 11.6, ADR 0036), after the commit.
        if (BrandFact::query()->where('brand_id', FactCatalog::brandId($response))->where('is_active', true)->exists()) {
            CheckTenantFacts::dispatch((int) $response->getAttribute('tenant_id'))->afterCommit();
        }
    }

    /** The model stage could not be completed at all: keep the deterministic results and flag the response. */
    public function abandon(Response $response): void
    {
        $this->guardTenant($response);

        DB::transaction(function () use ($response): void {
            $response->update(['llm_failed' => true]);
            $this->markExtracted($response);
        });
    }

    /** An empty answer has nothing to analyse: no model call, the run is simply extracted. */
    public function markExtracted(Response $response): void
    {
        $this->guardTenant($response);

        $response->run()->where('status', 'collected')->update(['status' => 'extracted']);
        // In the same transaction as the result: the answer's local day is aggregated again (T40-F3). Also when the
        // run was extracted before, since write() has replaced its mentions and citations.
        app(WorkChain::class)->extracted($response);
    }

    /** One usage event per provider call that was billed (docs/spec.md 12.2). */
    public function recordUsage(Response $response, ExtractionAttempt $attempt): void
    {
        $this->guardTenant($response);

        foreach ($attempt->outcomes as $outcome) {
            if ($outcome->inputTokens === 0 && $outcome->outputTokens === 0) {
                continue;
            }

            UsageEvent::query()->create([
                'run_id' => $response->run_id,
                'anthropic_operation' => $outcome->budgetOperation,
                'kind' => 'extraction',
                'occurred_at' => CarbonImmutable::now('UTC'),
                'calls' => 1,
                'input_tokens' => $outcome->inputTokens,
                'output_tokens' => $outcome->outputTokens,
                'search_calls' => 0,
                'cost_usd' => $this->cost->of($outcome),
                'billed_to' => 'platform',
            ]);
        }
    }

    /**
     * Citations from annotations win (docs/spec.md 8.2): when the response has any, the model only
     * supplies source_type for the ones that have none. Without annotations the model's citations are stored,
     * but only those whose URL or domain is in the answer text; a source the model made up is dropped (T39-F10).
     *
     * @param  list<ModelCitation>  $found
     */
    private function storeCitations(Response $response, array $found, DetectionCatalog $catalog): void
    {
        $existing = Citation::query()->where('response_id', $response->id)->orderBy('id')->get();
        $sourceTypes = [];
        foreach ($found as $citation) {
            $normalized = $this->urls->normalize($citation->url);
            if ($normalized !== null) {
                $sourceTypes[$normalized['url']] ??= $citation->sourceType;
            }
        }

        if ($existing->contains('from_annotation', true)) {
            foreach ($existing as $row) {
                $type = $sourceTypes[$row->url_normalized] ?? null;
                if ($row->from_annotation && $row->source_type === null && $type !== null) {
                    $row->update(['source_type' => $type]);
                }
            }

            return;
        }

        Citation::query()->where('response_id', $response->id)->where('from_annotation', false)->delete();

        $answerUrls = $this->urlsIn((string) $response->answer_text);
        $rank = 0;
        foreach ($sourceTypes as $url => $type) {
            $parts = $this->urls->normalize($url);
            if ($parts === null || ! $this->inAnswer($parts, $answerUrls, (string) $response->answer_text)) {
                continue;
            }
            $ownership = $catalog->domains->classify($parts['domain']);
            Citation::query()->create([
                'response_id' => $response->id,
                'url' => $url,
                'url_normalized' => $url,
                'domain' => $parts['domain'],
                'ownership' => $ownership['ownership'],
                'competitor_id' => $ownership['competitor_id'],
                'source_type' => $type,
                'rank_in_answer' => ++$rank,
                'from_annotation' => false,
            ]);
        }
    }

    /**
     * The normalized URLs written out in the answer text.
     *
     * @return array<string, true>
     */
    private function urlsIn(string $text): array
    {
        preg_match_all('#https?://[^\s<>()\[\]"\'`]+#i', $text, $matches);
        $urls = [];
        foreach ($matches[0] as $match) {
            $normalized = $this->urls->normalize(rtrim($match, '.,;:!?'));
            if ($normalized !== null) {
                $urls[$normalized['url']] = true;
            }
        }

        return $urls;
    }

    /**
     * Whether a model citation has support in the answer: the URL is written out, or its domain is named.
     *
     * @param  array{url: string, domain: string}  $parts
     * @param  array<string, true>  $answerUrls
     */
    private function inAnswer(array $parts, array $answerUrls, string $text): bool
    {
        if (isset($answerUrls[$parts['url']])) {
            return true;
        }

        return preg_match('/(?<![\p{L}\p{N}.\-])(?:www\.)?'.preg_quote($parts['domain'], '/').'(?![\p{L}\p{N}\-])/iu', $text) === 1;
    }

    private function guardTenant(Response $response): void
    {
        $contextTenantId = app(TenantContext::class)->require();
        $responseTenantId = (int) $response->getAttribute('tenant_id');
        if ($responseTenantId !== $contextTenantId) {
            throw TenantMismatchException::forModel(Response::class, $responseTenantId, $contextTenantId);
        }
    }
}
