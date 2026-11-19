<?php

namespace App\ActionEngine\Sources;

use App\ActionEngine\ActionCandidate;
use App\ActionEngine\ActionSource;
use App\ActionEngine\GenerationContext;
use App\ActionEngine\SourceOutcome;
use App\Enums\ActionType;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Response;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * fact_correction (docs/spec.md 11.3 and 11.6, ADR 0036): an active fact of the brand that at least
 * ekko.actions.fact_correction.min_responses answers in the window contradict. One action per brand and fact (brand
 * level, no market: a fact is the same in every market, and the same wrong claim is counted across them).
 *
 * The sources are the citations the model named as repeating the contradicted claims. The most cited page is the
 * source shown in the action; if it is on the brand's own domain the action says to correct the own page,
 * otherwise to ask the publisher. Without a named source the action says that it is not known.
 *
 * Every figure is read from the claims, responses and citations of the database. The whole effect counts (the share
 * is 1): each contradicted answer is an answer that tells the reader something false about the brand. The type is
 * ranked first within its brand (ActionType::rank()).
 */
final readonly class FactCorrectionSource implements ActionSource
{
    public function type(): ActionType
    {
        return ActionType::FactCorrection;
    }

    public function evaluate(GenerationContext $context): SourceOutcome
    {
        $brand = $context->brand;
        $days = (int) config('ekko.actions.window_days');
        // The window is whole local days, so its edges are the local midnights, as instants.
        [$fromDate, $toDate] = $context->window($days);
        $from = CarbonImmutable::parse($fromDate, $context->today->getTimezone())->startOfDay()->utc();
        $to = CarbonImmutable::parse($toDate, $context->today->getTimezone())->addDay()->startOfDay()->utc();
        $inWindow = fn (Builder $query) => $query->where('collected_at', '>=', $from)->where('collected_at', '<', $to)
            ->whereHas('run', fn (Builder $run) => $run->where('status', 'extracted'));

        $responses = Response::query()->whereHas('run.prompt.market', fn (Builder $market) => $market->where('brand_id', $brand->id))
            ->where($inWindow)->count();
        if ($responses < (int) config('ekko.actions.min_answers')) {
            return new SourceOutcome([], []);
        }

        $facts = BrandFact::query()->where('brand_id', $brand->id)->where('is_active', true)->orderBy('id')->get()->keyBy('id');
        $claims = Claim::query()
            ->where('brand_id', $brand->id)->whereIn('fact_id', $facts->keys())
            ->whereIn('verdict', [Claim::CONTRADICTED, Claim::SUPPORTED])
            ->whereHas('response', $inWindow)
            ->with(['response:id,run_id', 'response.run:id,prompt_id,engine_key'])
            ->orderBy('id')->get();

        $minimum = (int) config('ekko.actions.fact_correction.min_responses');
        $candidates = [];
        foreach ($claims->groupBy('fact_id') as $factId => $rows) {
            $contradicted = $rows->where('verdict', Claim::CONTRADICTED);
            $responseIds = $contradicted->pluck('response_id')->unique();
            if ($responseIds->count() < $minimum) {
                continue;
            }
            $fact = $facts[$factId];

            $prompts = [];
            $engines = [];
            foreach ($contradicted->unique('response_id') as $claim) {
                $run = $claim->response?->run;
                if ($run !== null) {
                    $prompts[$run->prompt_id] = ($prompts[$run->prompt_id] ?? 0) + 1;
                    $engines[$run->engine_key] = true;
                }
            }
            ksort($prompts);
            $engines = array_keys($engines);
            sort($engines);

            $sources = $this->sources($contradicted);
            $top = $sources[0] ?? null;
            $kind = $top === null ? 'unknown' : ($top['ownership'] === 'own' ? 'own' : 'other');

            $candidates[] = new ActionCandidate(
                type: ActionType::FactCorrection,
                marketId: null,
                subject: 'fact:'.$fact->id,
                evidence: [
                    'period' => ['from' => $fromDate, 'to' => $toDate, 'days' => $days],
                    'fact_id' => $fact->id,
                    'fact' => $fact->text,
                    'contradicted_responses' => $responseIds->count(),
                    'supported_responses' => $rows->where('verdict', Claim::SUPPORTED)->pluck('response_id')->unique()->count(),
                    'responses' => $responses,
                    'claims' => $contradicted->pluck('text')->unique()->take((int) config('ekko.actions.fact_correction.max_claim_examples'))->values()->all(),
                    'sources' => $sources,
                    'source_kind' => $kind,
                ],
                affectedPerMonth: GenerationContext::perMonth($responseIds->count(), $days),
                competitorOnlyShare: 1.0,
                prompts: $prompts,
                engineKeys: $engines,
                textParams: [
                    // Facts are written as sentences; the evidence sentence puts its own full stop after the fact.
                    'fact' => Str::limit(rtrim($fact->text, ' .'), 200),
                    'prompts' => count($prompts),
                    'source' => $top['url'] ?? '',
                    'source_kind' => $kind,
                    'days' => $days,
                ],
            );
        }

        // Every fact is judged, also those without a candidate, so an action expires when the answers stop contradicting it.
        return new SourceOutcome([null], $candidates);
    }

    /**
     * The pages the contradicted claims are based on, the most cited first.
     *
     * @param  Collection<int, Claim>  $contradicted
     * @return list<array{url: string, domain: string, ownership: string, responses: int}>
     */
    private function sources($contradicted): array
    {
        $ids = $contradicted->flatMap(fn (Claim $claim): array => $claim->source_citation_ids)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $byUrl = [];
        foreach (Citation::query()->whereIn('id', $ids)->orderBy('id')->get() as $citation) {
            $responses = $contradicted->filter(fn (Claim $claim): bool => in_array($citation->id, $claim->source_citation_ids, true))->pluck('response_id')->unique();
            $row = $byUrl[$citation->url_normalized] ?? ['url' => Str::limit($citation->url_normalized, 200, ''), 'domain' => $citation->domain, 'ownership' => $citation->ownership, 'responses' => []];
            // One page cited as own in one answer and not in another keeps the own mark: the domain is the brand's.
            $row['ownership'] = $row['ownership'] === 'own' || $citation->ownership === 'own' ? 'own' : $citation->ownership;
            $row['responses'] = [...$row['responses'], ...$responses->all()];
            $byUrl[$citation->url_normalized] = $row;
        }

        $sources = array_map(fn (array $row): array => [...$row, 'responses' => count(array_unique($row['responses']))], array_values($byUrl));
        usort($sources, fn (array $a, array $b): int => [$b['responses'], $a['url']] <=> [$a['responses'], $b['url']]);

        return array_slice($sources, 0, (int) config('ekko.actions.fact_correction.max_sources'));
    }
}
