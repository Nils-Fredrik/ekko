<?php

namespace App\Alerts\Detectors;

use App\Alerts\AlertCandidate;
use App\Alerts\AlertContext;
use App\Alerts\AlertType;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Prompt;

/**
 * citation_lost (docs/spec.md 11.1): on a core prompt and engine, an own domain was cited in the previous
 * round and is not in the latest. A round is a day with answers. The latest round must be recent
 * (ekko.alerts.fresh_days), so a prompt that stopped running does not alert.
 */
final readonly class CitationLost implements Detector
{
    public function __construct(private MetricsService $metrics) {}

    public function detect(AlertContext $context): array
    {
        $candidates = [];
        $from = $context->today()->subDays((int) config('ekko.alerts.round_history_days'))->toDateString();
        $to = $context->today()->subDay()->toDateString();
        $freshFrom = $context->today()->subDays((int) config('ekko.alerts.fresh_days'))->toDateString();

        foreach ($context->brands as $brand) {
            if ($context->rule(AlertType::CitationLost, $brand->id) === null) {
                continue;
            }

            $promptIds = Prompt::query()
                ->where('priority', 'core')->where('is_active', true)
                ->whereIn('market_id', $brand->markets()->where('is_active', true)->pluck('id'))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($promptIds === []) {
                continue;
            }

            $rounds = [];
            foreach ($this->metrics->citationRounds(new MetricsFilter($brand->id, $from, $to, promptIds: $promptIds)) as $round) {
                $rounds["{$round['prompt_id']}|{$round['engine_key']}"][] = $round;
            }

            foreach ($rounds as $series) {
                if (count($series) < 2) {
                    continue;
                }
                $latest = $series[count($series) - 1];
                $before = $series[count($series) - 2];

                if ($latest['date'] < $freshFrom || ($before['citation']->value ?? 0) <= 0 || ($latest['citation']->value ?? 0) > 0) {
                    continue;
                }

                $candidates[] = new AlertCandidate(AlertType::CitationLost, $brand->id, "prompt:{$latest['prompt_id']}:engine:{$latest['engine_key']}", [
                    'prompt_id' => $latest['prompt_id'],
                    'engine_key' => $latest['engine_key'],
                    'previous_round' => ['date' => $before['date'], 'citation' => $before['citation']->toArray()],
                    'latest_round' => ['date' => $latest['date'], 'citation' => $latest['citation']->toArray()],
                ]);
            }
        }

        return $candidates;
    }
}
