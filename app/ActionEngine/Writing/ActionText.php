<?php

namespace App\ActionEngine\Writing;

/**
 * The words of an action: title, description and the steps (docs/spec.md 11.3).
 */
final readonly class ActionText
{
    /**
     * @param  list<string>  $steps
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $steps,
    ) {}

    /**
     * Steps as stored on the action: each can be ticked off (T44).
     *
     * @return list<array{text: string, done: bool}>
     */
    public function stepRows(): array
    {
        return array_map(fn (string $text): array => ['text' => $text, 'done' => false], $this->steps);
    }
}
