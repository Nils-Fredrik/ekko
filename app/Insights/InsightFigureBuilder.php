<?php

namespace App\Insights;

use App\Dashboard\DashboardLabels;
use App\Dashboard\DashboardPeriod;
use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Metrics\MetricsSummary;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Prompt;
use Carbon\CarbonImmutable;

/**
 * Reads the figures behind an insight text from the same MetricsService calls the dashboards use, so the text
 * cannot show a number that differs from the page (ADR 0038). A change is only a finding when both periods rest
 * on at least ekko.insights.min_n answers; a figure with fewer is left out. The model is given directions and
 * placeholder names, never the figures.
 */
final class InsightFigureBuilder
{
    /** @var array<string, string> metric => property of MetricsSummary */
    private const RATES = [
        'visibility' => 'visibility',
        'share_of_voice' => 'shareOfVoice',
        'recommendation_rate' => 'recommendationRate',
        'citation_rate' => 'citationRate',
    ];

    /*
     * Which figures of a mover get a placeholder: only what the page shows (ADR 0038). The overview shows each
     * brand's visibility now and before; the brand page shows the value per engine but not the earlier one, and
     * no per-competitor visibility or per-prompt figure. The rest is named with a direction, without a number.
     */
    private const BOTH = 'both';

    private const NOW = 'now';

    private const NONE = 'none';

    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * One brand and one filter combination: the headline metrics, the biggest movements per engine, prompt and
     * competitor, model changes in the period and completed actions.
     */
    public function brand(Brand $brand, DashboardPeriod $period, MetricsFilter $filter, MetricsFilter $previous): InsightFigures
    {
        $now = $this->metrics->dashboard($filter);
        $before = $this->metrics->dashboard($previous);
        $figures = [];
        $findings = [];
        $names = [$brand->name];

        $anchored = false;
        foreach (self::RATES as $key => $property) {
            $current = $now['summary']->{$property};
            if (! $this->reliable($current)) {
                continue;
            }
            $anchored = $anchored || $key === 'visibility';
            $figures[$key.'_now'] = InsightFigure::percent((float) $current->value);
            $finding = ['id' => $key, 'topic' => $key, 'placeholders' => ['now' => $key.'_now']];
            $this->change($figures, $finding, $key.'_change', $current, $before['summary']->{$property});
            $findings[] = $finding;
        }
        $position = $now['summary']->averagePosition;
        if ($this->reliable($position)) {
            $figures['position_now'] = InsightFigure::number((float) $position->value);
            $findings[] = ['topic' => 'average_position', 'placeholders' => ['now' => 'position_now'], 'note' => 'a lower position is better'];
        }

        $labels = DashboardLabels::engines(array_keys($now['engines']));
        $engines = $this->movers(
            array_map(fn (MetricsSummary $s): Metric => $s->visibility, $now['engines']),
            array_map(fn (MetricsSummary $s): Metric => $s->visibility, $before['engines']),
            fn (string $key): string => $labels[$key] ?? DashboardLabels::engine($key),
        );
        $this->movements($figures, $findings, $names, 'engine', $engines, self::NOW);

        $competitors = $this->metrics->competitorComparison($filter);
        $competitorsBefore = $this->metrics->competitorComparison($previous);
        $byName = [];
        $beforeByName = [];
        foreach ($competitors as $row) {
            $byName[$row['name']] = $row['visibility'];
        }
        foreach ($competitorsBefore as $row) {
            $beforeByName[$row['name']] = $row['visibility'];
        }
        $this->movements($figures, $findings, $names, 'competitor', $this->movers($byName, $beforeByName, fn (string $name): string => $name), self::NONE);

        $prompts = $this->promptMovers($filter, $previous);
        $this->movements($figures, $findings, $names, 'prompt', $prompts, self::NONE);

        $changes = $this->metrics->modelChanges($period->from, $period->to, $filter->engineKeys);
        $changeLabels = DashboardLabels::engines($changes->pluck('engine_key')->unique()->all());
        foreach ($changes as $change) {
            $engine = $changeLabels[$change->engine_key] ?? DashboardLabels::engine($change->engine_key);
            $findings[] = ['topic' => 'model_change', 'engine' => $engine, 'from_model' => $change->from_model, 'to_model' => $change->to_model];
            array_push($names, $engine, $change->from_model, $change->to_model);
        }

        $this->completedActions($findings, $period, $brand->id);

        return new InsightFigures($figures, $findings, array_values(array_unique($names)), $anchored);
    }

    /**
     * The overview: the brands that moved most, model changes in the period and completed actions, across brands.
     *
     * @param  iterable<Brand>  $brands
     */
    public function overview(iterable $brands, DashboardPeriod $period): InsightFigures
    {
        $now = [];
        $before = [];
        $named = [];
        foreach ($brands as $brand) {
            $key = (string) $brand->id;
            $named[$key] = $brand->name;
            $now[$key] = $this->metrics->visibility($period->filter($brand->id));
            $before[$key] = $this->metrics->visibility($period->filter($brand->id, previous: true));
        }
        $figures = [];
        $findings = [];
        $names = array_values($named);

        $reliable = array_filter($now, fn (Metric $m): bool => $this->reliable($m));
        $movers = $this->movers($now, $before, fn (string $key): string => $named[$key]);
        $this->movements($figures, $findings, $names, 'brand', $movers, self::BOTH);

        $changes = $this->metrics->modelChanges($period->from, $period->to);
        $labels = DashboardLabels::engines($changes->pluck('engine_key')->unique()->all());
        foreach ($changes as $change) {
            $engine = $labels[$change->engine_key] ?? DashboardLabels::engine($change->engine_key);
            $findings[] = ['topic' => 'model_change', 'engine' => $engine, 'from_model' => $change->from_model, 'to_model' => $change->to_model];
            array_push($names, $engine, $change->from_model, $change->to_model);
        }
        $this->completedActions($findings, $period, null);

        return new InsightFigures($figures, $findings, array_values(array_unique($names)), $reliable !== [] && $movers !== []);
    }

    private function reliable(Metric $metric): bool
    {
        return $metric->value !== null && $metric->n >= (int) config('ekko.insights.min_n');
    }

    /**
     * @param  array<string, InsightFigure>  $figures
     * @param  array<string, mixed>  $finding
     */
    private function change(array &$figures, array &$finding, string $key, Metric $now, Metric $before): void
    {
        if (! $this->reliable($before)) {
            $finding['note'] = 'the earlier period has too few answers: do not describe a change';

            return;
        }
        $change = InsightFigure::points((float) $now->value, (float) $before->value);
        $figures[$key] = $change;
        $finding['direction'] = $this->direction($change);
        $finding['placeholders']['change'] = $key;
    }

    private function direction(InsightFigure $change): string
    {
        return match ($change->direction) {
            1 => 'up',
            -1 => 'down',
            default => 'unchanged',
        };
    }

    /**
     * The biggest movers: rows where both periods are reliable and the change is visible, largest first.
     *
     * @param  array<array-key, Metric>  $now
     * @param  array<array-key, Metric>  $before
     * @param  callable(string): string  $label
     * @return list<array{key: string, label: string, now: Metric, change: InsightFigure}>
     */
    private function movers(array $now, array $before, callable $label): array
    {
        $rows = [];
        foreach ($now as $key => $current) {
            $earlier = $before[$key] ?? null;
            if ($earlier === null || ! $this->reliable($current) || ! $this->reliable($earlier)) {
                continue;
            }
            $change = InsightFigure::points((float) $current->value, (float) $earlier->value);
            if ($change->direction === 0) {
                continue;
            }
            $rows[] = ['key' => (string) $key, 'label' => $label((string) $key), 'now' => $current, 'change' => $change];
        }
        usort($rows, fn (array $a, array $b): int => [$b['change']->value, $a['label']] <=> [$a['change']->value, $b['label']]);

        return array_slice($rows, 0, (int) config('ekko.insights.max_movements'));
    }

    /**
     * @param  array<string, InsightFigure>  $figures
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $names
     * @param  list<array{key: string, label: string, now: Metric, change: InsightFigure}>  $movers
     */
    private function movements(array &$figures, array &$findings, array &$names, string $type, array $movers, string $show): void
    {
        foreach ($movers as $index => $mover) {
            $prefix = $type.'_'.($index + 1);
            $entity = $type.':'.$mover['key'];
            $finding = ['id' => $entity, 'topic' => $type.'_visibility', 'name' => $mover['label'], 'direction' => $this->direction($mover['change'])];
            if ($show !== self::NONE) {
                $figures[$prefix.'_now'] = InsightFigure::percent((float) $mover['now']->value, $entity);
                $finding['placeholders'] = ['now' => $prefix.'_now'];
            }
            if ($show === self::BOTH) {
                $figures[$prefix.'_change'] = new InsightFigure($mover['change']->kind, $mover['change']->value, $mover['change']->direction, $entity);
                $finding['placeholders']['change'] = $prefix.'_change';
            }
            $findings[] = $finding;
            $names[] = $mover['label'];
        }
    }

    /**
     * @return list<array{key: string, label: string, now: Metric, change: InsightFigure}>
     */
    private function promptMovers(MetricsFilter $filter, MetricsFilter $previous): array
    {
        $now = $this->perPrompt($filter);
        $before = $this->perPrompt($previous);
        $texts = Prompt::query()->whereIn('id', array_keys($now))->pluck('text', 'id');

        return $this->movers($now, $before, fn (string $id): string => mb_substr((string) $texts->get((int) $id, ''), 0, 120));
    }

    /**
     * @return array<array-key, Metric> prompt id => visibility over all engines
     */
    private function perPrompt(MetricsFilter $filter): array
    {
        $sums = [];
        foreach ($this->metrics->visibilityByPromptEngine($filter) as $row) {
            $n = $row['visibility']['n'];
            $sums[$row['prompt_id']]['n'] = ($sums[$row['prompt_id']]['n'] ?? 0) + $n;
            $sums[$row['prompt_id']]['mentioned'] = ($sums[$row['prompt_id']]['mentioned'] ?? 0) + (int) round(($row['visibility']['value'] ?? 0) * $n);
        }
        $metrics = [];
        foreach ($sums as $id => $sum) {
            $metrics[(string) $id] = new Metric($sum['n'] > 0 ? $sum['mentioned'] / $sum['n'] : null, $sum['n']);
        }

        return $metrics;
    }

    /**
     * Completed actions are named without a number: no page shows the count.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function completedActions(array &$findings, DashboardPeriod $period, ?int $brandId): void
    {
        $from = CarbonImmutable::parse($period->from, $period->timezone)->startOfDay()->utc();
        $until = CarbonImmutable::parse($period->to, $period->timezone)->addDay()->startOfDay()->utc();
        $done = Action::query()
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->where('completed_at', '>=', $from)->where('completed_at', '<', $until)->exists();
        if ($done) {
            $findings[] = ['topic' => 'completed_actions', 'note' => 'some actions were completed in the period'];
        }
    }
}
