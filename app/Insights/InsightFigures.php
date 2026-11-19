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
     * @return array<string, int>
     */
    public function directions(): array
    {
        $directions = [];
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
