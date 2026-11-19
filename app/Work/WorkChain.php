<?php

namespace App\Work;

use App\Models\ExtractionJob;
use App\Models\Response;
use App\Models\Run;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * The producers of chained processing work (docs/spec.md 7.1, ADR 0032, T40-F3): what finished application work
 * registers as the next step. Every method runs in the transaction of the data change it stands for, so the work
 * exists exactly when the change commits, and does nothing while ekko.work.chaining is off.
 *
 * - A collected answer: extraction of its collection group (run_batch:<id>), at once when the group has no run in
 *   progress any more or holds a full model batch, otherwise after ekko.work.extraction.group_wait_seconds, so one
 *   slow engine, a failure or a quota stop never holds the rest back for long.
 * - A run that ends without an answer (failed, skipped_quota): the same, if its group is now finished and has
 *   answers waiting.
 * - A submitted model batch: its first status check (extraction_job:<id>).
 * - An extracted answer: the aggregation of its tenant-local day, from responses.collected_at, however late.
 * - A usage event: the usage roll-up of its UTC month.
 *
 * The run states are the authoritative source; run_batches' counters are only refreshed by the dispatcher.
 */
final class WorkChain
{
    /** Runs that have not ended yet. */
    private const IN_PROGRESS = ['queued', 'running', 'submitted'];

    /** @var array<int, string> tenant id => timezone, per instance */
    private array $timezones = [];

    public function __construct(private readonly WorkSignals $signals, private readonly TenantContext $context) {}

    public static function enabled(): bool
    {
        return (bool) config('ekko.work.chaining');
    }

    /** An answer of the run was stored and the run is collected. */
    public function collected(Run $run): void
    {
        if (! self::enabled()) {
            return;
        }

        $batchId = (int) $run->run_batch_id;
        // Only answers a new batch can take: those already in a submitted batch stay collected until their results
        // are written, and must not make every later answer of the group look like a full batch.
        $waiting = Response::query()
            ->whereHas('run', fn ($query) => $query->where('run_batch_id', $batchId)->where('status', 'collected'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('extraction_jobs')
                ->whereColumn('extraction_jobs.tenant_id', 'responses.tenant_id')
                ->where('extraction_jobs.status', 'submitted')
                ->whereRaw('extraction_jobs.response_ids @> to_jsonb(responses.id)'))
            ->count();
        $full = $waiting >= max(1, (int) config('ekko.anthropic.extraction.batch_size'));
        $notBefore = $full || ! $this->inProgress($batchId)
            ? null
            : CarbonImmutable::now('UTC')->addSeconds(max(0, (int) config('ekko.work.extraction.group_wait_seconds')));

        $this->signals->signal(WorkKind::Extraction, WorkScope::runBatch($batchId), $notBefore);
    }

    /** The run ended without an answer (failed or skipped_quota): its group may be finished now. */
    public function ended(Run $run): void
    {
        if (! self::enabled()) {
            return;
        }

        $batchId = (int) $run->run_batch_id;
        if ($this->inProgress($batchId) || ! Run::query()->where('run_batch_id', $batchId)->where('status', 'collected')->exists()) {
            return;
        }

        $this->signals->signal(WorkKind::Extraction, WorkScope::runBatch($batchId));
    }

    /** A model batch was submitted: its first status check. */
    public function submitted(ExtractionJob $job): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->signals->signal(
            WorkKind::Extraction,
            WorkScope::extractionJob((int) $job->id),
            CarbonImmutable::now('UTC')->addSeconds(max(0, (int) config('ekko.work.extraction.status_first_seconds'))),
        );
    }

    /** The answer's run is extracted (written, empty or given up): its local day is aggregated again. */
    public function extracted(Response $response): void
    {
        if (! self::enabled() || $response->collected_at === null) {
            return;
        }

        $this->signals->signal(WorkKind::Aggregation, WorkScope::localDate($response->collected_at, $this->timezone()));
    }

    /** A usage event was recorded: its UTC month is rolled up again. */
    public function usageRecorded(UsageEvent $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->signals->signal(WorkKind::UsageRollup, WorkScope::month(CarbonImmutable::parse($event->occurred_at ?? 'now', 'UTC')));
    }

    private function inProgress(int $batchId): bool
    {
        return Run::query()->where('run_batch_id', $batchId)->whereIn('status', self::IN_PROGRESS)->exists();
    }

    private function timezone(): string
    {
        $tenantId = $this->context->require();

        // The same reading as DailyAggregator: an unknown or empty timezone counts as UTC.
        return $this->timezones[$tenantId] ??= (function () use ($tenantId): string {
            $name = Team::query()->whereKey($tenantId)->value('timezone');
            try {
                return (new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC'))->getName();
            } catch (Throwable) {
                return 'UTC';
            }
        })();
    }
}
