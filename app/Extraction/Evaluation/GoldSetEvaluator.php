<?php

namespace App\Extraction\Evaluation;

use App\Detection\AliasPattern;
use App\Detection\DomainOwnership;
use App\Detection\EntityMatcher;
use App\Detection\UrlNormalizer;
use App\Extraction\AssembledExtraction;
use App\Extraction\ExtractionAssembler;
use App\Extraction\ExtractionCost;
use App\Extraction\ExtractionPromptBuilder;
use App\Extraction\ExtractionRequest;
use App\Extraction\ExtractionResult;
use App\Extraction\ExtractionRunner;
use App\Extraction\SourceTypeRules;
use Brick\Math\BigDecimal;
use Closure;
use InvalidArgumentException;

/**
 * Evaluates model-based extraction against the gold set (docs/spec.md 18, T20). It runs the same path as
 * production, without a database: deterministic detection on the gold catalog, the extraction prompt, a
 * Message Batch, validation with the single retry, and the assembly of the two stages. The model's own
 * output and the assembled result are then compared with the expected mentions.
 *
 * The gold file is `database/evals/extraction-gold.json`: a catalog and responses with the expected
 * entities (`entity`, `list_position`, `is_recommended`) and citations (`normalized`, `source_type`).
 */
final class GoldSetEvaluator
{
    public function __construct(
        private readonly ExtractionPromptBuilder $prompts = new ExtractionPromptBuilder,
        private readonly ExtractionAssembler $assembler = new ExtractionAssembler,
        private readonly EntityMatcher $matcher = new EntityMatcher,
        private readonly UrlNormalizer $urls = new UrlNormalizer,
        private readonly ExtractionCost $cost = new ExtractionCost,
        private readonly SourceTypeRules $sourceTypeRules = new SourceTypeRules,
    ) {}

    /**
     * @param  Closure(string): void|null  $waitForBatch  called between polls of a batch that has not ended
     */
    public function evaluate(string $goldPath, ExtractionRunner $runner, ?Closure $waitForBatch = null): EvaluationReport
    {
        $gold = json_decode((string) @file_get_contents($goldPath), true);
        if (! is_array($gold) || ! isset($gold['catalog'], $gold['responses'])) {
            throw new InvalidArgumentException('The gold set file is missing or invalid.');
        }

        [$aliases, $entityIds, $domains] = $this->catalog($gold['catalog']);

        $requests = [];
        $deterministic = [];
        foreach ($gold['responses'] as $response) {
            $deterministic[$response['id']] = $this->matcher->detect($response['text'], $aliases);
            $requests[$response['id']] = $this->prompts->build(
                $response['id'], $response['text'], $response['language'], $aliases, $deterministic[$response['id']],
                array_column($response['citations'], 'url'),
            );
        }

        $attempts = [];
        $size = max(1, (int) config('ekko.anthropic.extraction.batch_size'));
        foreach (array_chunk($requests, $size, true) as $chunk) {
            $batchId = $runner->submit(array_values($chunk));
            while (! $runner->ended($batchId)) {
                $waitForBatch !== null ? $waitForBatch($batchId) : usleep(250_000);
            }
            $attempts += $runner->collect($batchId, array_keys($chunk), fn (string $id): ?ExtractionRequest => $requests[$id] ?? null);
        }

        $model = ['recommended' => new Metric, 'position' => new Metric];
        $assembled = ['recommended' => new Metric, 'position' => new Metric];
        $sourceTypes = ['correct' => 0, 'total' => 0];
        $storedSourceTypes = ['correct' => 0, 'total' => 0];
        $sourceTypeMisses = [];
        $byLanguage = [];
        $retried = 0;
        $failed = [];
        $tokens = [0, 0];
        $cost = BigDecimal::zero();

        foreach ($gold['responses'] as $response) {
            $id = $response['id'];
            $byLanguage[$response['language']] = ($byLanguage[$response['language']] ?? 0) + 1;
            $attempt = $attempts[$id];

            $retried += $attempt->retried ? 1 : 0;
            if ($attempt->failed()) {
                $failed[] = $id;
            }
            foreach ($attempt->outcomes as $outcome) {
                $tokens[0] += $outcome->inputTokens;
                $tokens[1] += $outcome->outputTokens;
                $cost = $cost->plus($this->cost->of($outcome));
            }

            $expected = [];
            foreach ($response['expected'] as $row) {
                [$type, $key] = explode(':', $row['entity'], 2);
                $expected[$entityIds[$type][$key]['type'].':'.$entityIds[$type][$key]['id']] = $row;
            }

            $this->score($model, $this->modelValues($attempt->result), $expected);

            $combined = $this->assembler->assemble($response['text'], $deterministic[$id], $attempt->result, $aliases);
            $this->score($assembled, $this->assembledValues($combined), $expected);

            foreach ($response['citations'] as $citation) {
                if (! isset($citation['source_type'])) {
                    continue;
                }
                $modelType = $this->sourceType($attempt->result, $citation['normalized']);
                $sourceTypes['total']++;
                $sourceTypes['correct'] += $modelType === $citation['source_type'] ? 1 : 0;

                // What is stored: the deterministic rules over the model's type, as in ExtractionWriter. An answer whose
                // model stage failed keeps no source types at all, since the writer then skips the citations.
                $domain = (string) ($this->urls->normalize($citation['url'])['domain'] ?? '');
                $stored = $attempt->result === null
                    ? null
                    : $this->sourceTypeRules->resolve($domain, $domains->classify($domain)['ownership'], $modelType);
                $storedSourceTypes['total']++;
                if ($stored === $citation['source_type']) {
                    $storedSourceTypes['correct']++;
                } else {
                    $sourceTypeMisses[] = "{$id} {$citation['normalized']}: ".($stored ?? '-')." ≠ {$citation['source_type']}";
                }
            }
        }

        return new EvaluationReport(
            count($gold['responses']), $byLanguage, $model, $assembled, $sourceTypes, $retried, $failed,
            $tokens[0], $tokens[1], (string) $cost->toScale(8), $storedSourceTypes, $sourceTypeMisses,
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array{0: list<AliasPattern>, 1: array<string, array<string, array{type: string, id: int}>>, 2: DomainOwnership}
     */
    private function catalog(array $catalog): array
    {
        $aliases = [];
        $ids = ['brand' => [], 'competitor' => []];
        $brandDomains = [];
        $competitorDomains = [];

        foreach (['brand' => $catalog['brands'], 'competitor' => $catalog['competitors']] as $type => $rows) {
            foreach (array_values($rows) as $index => $row) {
                $id = $index + 1;
                $ids[$type][$row['key']] = ['type' => $type, 'id' => $id];
                foreach ($row['aliases'] as $alias) {
                    $aliases[] = new AliasPattern($type, $id, $alias['alias'], $alias['mode']);
                }
                foreach ($row['domains'] ?? [] as $domain) {
                    if ($type === 'brand') {
                        $brandDomains[] = ['domain' => $domain['domain'], 'include_subdomains' => (bool) $domain['include_subdomains']];
                    } else {
                        $competitorDomains[] = ['domain' => $domain, 'competitor_id' => $id];
                    }
                }
            }
        }

        return [$aliases, $ids, new DomainOwnership($brandDomains, $competitorDomains)];
    }

    /**
     * Known-entity values by entity key, first report per entity.
     *
     * @return array<string, array{recommended: bool, position: int|null}>
     */
    private function modelValues(?ExtractionResult $result): array
    {
        $values = [];
        foreach ($result->mentions ?? [] as $mention) {
            $key = $mention->entityKey();
            if ($key !== null && ! isset($values[$key])) {
                $values[$key] = ['recommended' => $mention->isRecommended, 'position' => $mention->listPosition];
            }
        }

        return $values;
    }

    /** @return array<string, array{recommended: bool, position: int|null}> */
    private function assembledValues(AssembledExtraction $extraction): array
    {
        $values = [];
        foreach ($extraction->mentions as $mention) {
            if ($mention->entityId !== null) {
                $values[$mention->entityType.':'.$mention->entityId] = ['recommended' => $mention->isRecommended, 'position' => $mention->listPosition];
            }
        }

        return $values;
    }

    /**
     * @param  array{recommended: Metric, position: Metric}  $metrics
     * @param  array<string, array{recommended: bool, position: int|null}>  $values
     * @param  array<string, array<string, mixed>>  $expected
     */
    private function score(array $metrics, array $values, array $expected): void
    {
        foreach ($expected as $row) {
            $metrics['recommended']->expected += $row['is_recommended'] ? 1 : 0;
            $metrics['position']->expected += $row['list_position'] !== null ? 1 : 0;
        }

        foreach ($values as $key => $value) {
            $row = $expected[$key] ?? null;

            if ($value['recommended']) {
                $metrics['recommended']->predicted++;
                $metrics['recommended']->truePositives += $row !== null && $row['is_recommended'] ? 1 : 0;
            }
            if ($value['position'] !== null) {
                $metrics['position']->predicted++;
                $metrics['position']->truePositives += $row !== null && $row['list_position'] === $value['position'] ? 1 : 0;
            }
        }
    }

    private function sourceType(?ExtractionResult $result, string $normalizedUrl): ?string
    {
        foreach ($result->citations ?? [] as $citation) {
            if (($this->urls->normalize($citation->url)['url'] ?? null) === $normalizedUrl) {
                return $citation->sourceType;
            }
        }

        return null;
    }
}
