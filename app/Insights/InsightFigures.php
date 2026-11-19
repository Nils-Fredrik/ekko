<?php

namespace App\Insights;

/**
 * What the model is told, and what the code fills in: the figures behind each placeholder, the findings the model
 * may mention (without any figures, only placeholders and directions), and the names it may write in full.
 */
final readonly class InsightFigures
{
    /**
     * @param  array<string, InsightFigure>  $figures  placeholder => figure
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $names
     */
    public function __construct(
        public array $figures,
        public array $findings,
        public array $names,
        public bool $usable,
    ) {}

    /**
     * The direction of every change the text may describe: of each figure (placeholder => -1, 0 or 1) and of each
     * finding that has a direction (`finding:<id>` => up, down or unchanged), so the text can be hidden when
     * one of them turns, also for a finding the text only names (an engine, a competitor, a prompt).
     *
     * @return array<string, int|string>
     */
    public function directions(): array
    {
        $directions = [];
        foreach ($this->findings as $finding) {
            if (isset($finding['id'], $finding['direction'])) {
                $directions['finding:'.$finding['id']] = $finding['direction'];
            }
        }
        foreach ($this->figures as $key => $figure) {
            if ($figure->direction !== null) {
                $directions[$key] = $figure->direction;
            }
        }

        return $directions;
    }

    /**
     * @return array<string, string> placeholder => the entity (engine, brand, competitor, prompt) it belongs to
     */
    public function entities(): array
    {
        $entities = [];
        foreach ($this->figures as $key => $figure) {
            if ($figure->entity !== null) {
                $entities[$key] = $figure->entity;
            }
        }

        return $entities;
    }
}
