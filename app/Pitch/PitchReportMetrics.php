<?php

namespace App\Pitch;

use App\Metrics\DailyAggregator;
use App\Metrics\Formulas;
use App\Metrics\Metric;
use App\Models\Brand;
use Carbon\CarbonImmutable;

/** Pitch windows can end mid-day, so daily aggregates cannot separate pitch and customer answers. */
final class PitchReportMetrics
{
    public function __construct(private DailyAggregator $aggregator) {}

    /** @return array{visibility: array{value: float|null, n: int, low_sample: bool}, competitors: list<array{name: string, visibility: array{value: float|null, n: int, low_sample: bool}}>, sources: list<array{domain: string, citations: int}>} */
    public function forWindow(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$daily, $sources] = $this->aggregator->compute($start, $end);
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
        $competitors = array_map(fn (array $row): array => ['name' => $row['name'], 'visibility' => $row['visibility']], $competitors);

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
            'competitors' => $competitors,
            'sources' => $domains,
        ];
    }
}
