<?php

namespace App\Pitch;

use App\Metrics\DailyAggregator;
use App\Metrics\Formulas;
use App\Metrics\Metric;
use App\Models\Brand;
use App\Models\Response;
use Carbon\CarbonImmutable;

/** Pitch windows can end mid-day, so daily aggregates cannot separate pitch and customer answers. */
final class PitchReportMetrics
{
    public function __construct(private DailyAggregator $aggregator) {}

    /** @return array{visibility: array{value: float|null, n: int, low_sample: bool}, mentions: int, competitors: list<array{name: string, mentions: int, visibility: array{value: float|null, n: int, low_sample: bool}}>, sources: list<array{domain: string, citations: int}>, reduced_quality: int} */
    public function forWindow(Brand $brand, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $runCreatedBefore): array
    {
        [$daily, $sources] = $this->aggregator->compute($start, $end, $runCreatedBefore);
        $daily = array_filter($daily, fn (array $row): bool => $row['brand_id'] === $brand->id);
        $sources = array_filter($sources, fn (array $row): bool => $row['brand_id'] === $brand->id);
        $responses = array_sum(array_column($daily, 'responses'));
        $mentioned = array_sum(array_column($daily, 'brand_mentioned'));
        $competitors = [];
        foreach ($brand->competitors()->where('is_active', true)->orderBy('name')->get() as $competitor) {
            $count = array_sum(array_map(fn (array $row): int => (int) ($row['competitor_mentions'][$competitor->id] ?? 0), $daily));
            $competitors[] = ['name' => $competitor->name, 'count' => $count, 'visibility' => (new Metric(Formulas::ratio($count, $responses), $responses))->toArray()];
        }
        usort($competitors, fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));
        $competitors = array_map(fn (array $row): array => ['name' => $row['name'], 'mentions' => $row['count'], 'visibility' => $row['visibility']], $competitors);

        $domains = [];
        foreach ($sources as $row) {
            $domain = $row['domain'];
            $domains[$domain] ??= ['domain' => $domain, 'citations' => 0, 'responses' => 0];
            $domains[$domain]['citations'] += $row['citations'];
            $domains[$domain]['responses'] += $row['responses_with_domain'];
        }
        $domains = array_values($domains);
        usort($domains, fn (array $a, array $b): int => ($b['responses'] <=> $a['responses']) ?: strcmp($a['domain'], $b['domain']));
        $domains = array_map(fn (array $row): array => ['domain' => $row['domain'], 'citations' => $row['citations']], array_slice($domains, 0, 10));

        return [
            'visibility' => (new Metric(Formulas::ratio($mentioned, $responses), $responses))->toArray(),
            'mentions' => $mentioned,
            'competitors' => $competitors,
            'sources' => $domains,
            'reduced_quality' => Response::query()->where('llm_failed', true)
                ->where('collected_at', '>=', $start)->where('collected_at', '<', $end)
                ->whereHas('run', fn ($query) => $query->where('status', 'extracted')->where('created_at', '<', $runCreatedBefore)
                    ->whereHas('prompt.market', fn ($market) => $market->where('brand_id', $brand->id)))
                ->count(),
        ];
    }
}
