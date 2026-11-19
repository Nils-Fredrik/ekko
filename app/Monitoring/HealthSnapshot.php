<?php

namespace App\Monitoring;

use App\Enums\TenantStatus;
use App\Models\ExtractionJob;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\WorkSignals;
use Carbon\CarbonImmutable;

/** Aggregate numeric operational signals without loading prompts, responses or credentials. */
final class HealthSnapshot
{
    public function __construct(private TenantContext $context, private TenantRls $rls, private WorkSignals $work) {}

    /** @return array{engines: array<string, array{total: int, failed: int, unhealthy: bool}>, collection_overdue: int, extraction_overdue: int, work_stopped: int} */
    public function collect(CarbonImmutable $now): array
    {
        $engines = [];
        $collection = 0;
        $extraction = 0;
        $stopped = 0;
        foreach (Team::query()->where('status', '<>', TenantStatus::Closed->value)->select('id')->lazyById() as $tenant) {
            $snapshot = $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], fn () => $this->tenantSnapshot($now)));
            $collection += $snapshot['collection_overdue'];
            $extraction += $snapshot['extraction_overdue'];
            $stopped += $snapshot['work_stopped'];
            foreach ($snapshot['engines'] as $key => $engine) {
                $engines[$key] ??= ['total' => 0, 'failed' => 0, 'unhealthy' => false];
                $engines[$key]['total'] += $engine['total'];
                $engines[$key]['failed'] += $engine['failed'];
                $engines[$key]['unhealthy'] = $engines[$key]['failed'] * 5 > $engines[$key]['total'];
            }
        }

        return ['engines' => $engines, 'collection_overdue' => $collection, 'extraction_overdue' => $extraction, 'work_stopped' => $stopped];
    }

    /** @return array{engines: array<string, array{total: int, failed: int}>, collection_overdue: int, extraction_overdue: int, work_stopped: int} */
    private function tenantSnapshot(CarbonImmutable $now): array
    {
        $engines = [];
        $rows = Run::query()->where('finished_at', '>=', $now->subDay())->where('finished_at', '<=', $now)
            ->whereIn('status', ['collected', 'extracted', 'failed'])
            ->selectRaw("engine_key, COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'failed') AS failed")
            ->groupBy('engine_key')->get();
        foreach ($rows as $row) {
            $engines[(string) $row->engine_key] = ['total' => (int) $row->getAttribute('total'), 'failed' => (int) $row->getAttribute('failed')];
        }
        // Batch counters are refreshed by dispatch, so test actual pending runs rather than stale counters.
        $collection = RunBatch::query()->where('scheduled_for', '<', $now->subHours(6))
            ->whereHas('runs', fn ($query) => $query->whereIn('status', ['queued', 'running', 'submitted']))->count();
        $extraction = ExtractionJob::query()->where('status', 'submitted')->where('submitted_at', '<', $now->subDay())->count();

        // Durable work that failed for longer than the stop window (ADR 0032): stays an issue until it is re-armed.
        $stopped = $this->work->stoppedCount();

        return ['engines' => $engines, 'collection_overdue' => $collection, 'extraction_overdue' => $extraction, 'work_stopped' => $stopped];
    }
}
