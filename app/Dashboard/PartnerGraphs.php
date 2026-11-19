<?php

namespace App\Dashboard;

use App\Metrics\Metric;
use App\Metrics\MetricsService;
use App\Models\Action;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Portfolio graphs are computed in the client tenant, after permission checks on every request. */
final class PartnerGraphs
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRls $rls,
        private readonly TenantAccess $access,
        private readonly GraphCache $cache,
        private readonly MetricsService $metrics,
    ) {}

    /**
     * @param  Collection<int, Team>  $clients
     * @return list<array<string, mixed>>
     */
    public function forClients(User $user, Collection $clients): array
    {
        $rows = [];
        foreach ($clients as $client) {
            if (! $this->access->canAccess($user, $client)) {
                continue;
            }

            $rows[] = $this->context->run($client->id, fn (): array => $this->rls->run([$client->id], function () use ($client, $user): array {
                // Cache storage is not protected by RLS: policies run before all cache reads.
                Gate::forUser($user)->authorize('viewAny', Brand::class);
                Gate::forUser($user)->authorize('viewAny', Alert::class);
                Gate::forUser($user)->authorize('viewAny', Action::class);

                return $this->client($client);
            }));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function client(Team $client): array
    {
        $period = DashboardPeriod::endingToday(28, $client->timezone);
        $days = [];
        $current = [];
        $previous = [];
        foreach (Brand::query()->orderBy('id')->get() as $brand) {
            $filter = $period->filter($brand->id);
            $series = $this->cache->remember('partner.visibility.series', $filter, $period->timezone, fn (): array => array_map(
                fn (array $row): array => ['date' => $row['period'], ...$row['summary']->visibility->toArray()],
                $this->metrics->series($filter),
            ));
            foreach ($series as $point) {
                $days[$point['date']][] = $point;
                $current[] = $point;
            }

            $previousFilter = $period->filter($brand->id, previous: true);
            $previous[] = $this->cache->remember('partner.visibility.previous', $previousFilter, $period->timezone,
                fn (): array => $this->metrics->visibility($previousFilter)->toArray());
        }

        $points = [];
        $date = CarbonImmutable::parse($period->from, $period->timezone);
        for ($i = 0; $i < 28; $i++, $date = $date->addDay()) {
            $key = $date->toDateString();
            $points[] = ['x' => $key, ...$this->average($days[$key] ?? [])];
        }
        $currentMetric = $this->average($current);
        $previousMetric = $this->average($previous);

        return [
            'id' => $client->id,
            'series' => $points,
            'visibility' => $currentMetric,
            'previous_visibility' => $previousMetric,
            'change' => $currentMetric['value'] === null || $previousMetric['value'] === null
                ? null : $currentMetric['value'] - $previousMetric['value'],
            // Open operational counts are deliberately not cached.
            'open_alerts' => Alert::query()->whereNull('acknowledged_at')->count(),
            'open_actions' => Action::query()->open()->count(),
        ];
    }

    /**
     * @param  list<array{value: float|null, n: int}>  $samples
     * @return array{value: float|null, n: int, low_sample: bool}
     */
    private function average(array $samples): array
    {
        $n = 0;
        $weighted = 0.0;
        foreach ($samples as $sample) {
            if ($sample['value'] !== null) {
                $n += $sample['n'];
                $weighted += $sample['value'] * $sample['n'];
            }
        }

        return ['value' => $n === 0 ? null : $weighted / $n, 'n' => $n, 'low_sample' => $n < Metric::LOW_SAMPLE];
    }
}
