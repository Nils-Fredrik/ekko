<?php

namespace App\Alerts;

use App\Alerts\Detectors\CitationLost;
use App\Alerts\Detectors\CompetitorTakeover;
use App\Alerts\Detectors\Detector;
use App\Alerts\Detectors\ModelChange;
use App\Alerts\Detectors\NewCompetitor;
use App\Alerts\Detectors\VisibilityDrop;
use App\Models\Alert;
use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the detectors for the tenant in the context and stores an alert for every condition found that has not
 * been alerted within its cooldown (docs/spec.md 11.1, ADR 0017). Quota and integration_error alerts are not
 * detected here: the hourly usage rollup (ADR 0013) and the ClickHouse and GA4 syncs create them when the
 * condition occurs, and the delivery sends them with the rest.
 */
final class AlertEvaluator
{
    /** @var list<class-string<Detector>> */
    private const DETECTORS = [
        VisibilityDrop::class,
        CompetitorTakeover::class,
        CitationLost::class,
        NewCompetitor::class,
        ModelChange::class,
    ];

    /**
     * @return list<Alert> the alerts created
     */
    public function evaluate(CarbonImmutable $now, string $timezone): array
    {
        $context = new AlertContext(
            $now,
            $now->setTimezone($timezone),
            Brand::query()->where('status', 'active')->orderBy('id')->get(),
        );

        $created = [];
        foreach (self::DETECTORS as $class) {
            // A savepoint per detector: a failing detector is reported and must not stop the others, or leave
            // the job's transaction aborted.
            try {
                $created = [...$created, ...DB::transaction(fn (): array => $this->store($context, app($class)->detect($context)))];
            } catch (Throwable $failure) {
                report($failure);
            }
        }

        return $created;
    }

    /**
     * @param  list<AlertCandidate>  $candidates
     * @return list<Alert>
     */
    private function store(AlertContext $context, array $candidates): array
    {
        $created = [];

        foreach ($candidates as $candidate) {
            $rule = $context->rule($candidate->type, $candidate->brandId);
            if ($rule === null || $this->cooledDown($rule->id, $candidate, $context->now)) {
                continue;
            }

            $created[] = $rule->alerts()->create([
                'subject' => $candidate->subject,
                'triggered_at' => $context->now->utc(),
                'payload' => $candidate->payload,
            ]);
        }

        return $created;
    }

    private function cooledDown(int $ruleId, AlertCandidate $candidate, CarbonImmutable $now): bool
    {
        return Alert::query()
            ->where('alert_rule_id', $ruleId)
            ->where('subject', $candidate->subject)
            ->where('triggered_at', '>', $now->utc()->subDays($candidate->type->cooldownDays()))
            ->exists();
    }
}
