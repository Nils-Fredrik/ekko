<?php

namespace App\Prompts;

/**
 * One unknown brand waiting for a decision (docs/ux-arbeidsflate.md 6.3): its name key, the most used spelling,
 * every spelling, the distinct answers that name it and their share of the answers in the markets, the markets and
 * engines it was seen in, when it was last seen and a rule-based suggestion.
 */
final readonly class QueueItem
{
    /**
     * @param  list<string>  $variants
     * @param  list<int>  $markets
     * @param  list<string>  $engines
     * @param  array{decision: string, target_type?: string, target_id?: int, target_name?: string}|null  $suggestion
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $variants,
        public int $answers,
        public ?float $share,
        public int $n,
        public array $markets,
        public array $engines,
        public string $lastSeen,
        public ?array $suggestion,
    ) {}

    /**
     * @return array{key: string, name: string, variants: list<string>, answers: int, share: float|null, n: int, markets: list<int>, engines: list<string>, last_seen: string, suggestion: array{decision: string, target_type?: string, target_id?: int, target_name?: string}|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'variants' => $this->variants,
            'answers' => $this->answers,
            'share' => $this->share,
            'n' => $this->n,
            'markets' => $this->markets,
            'engines' => $this->engines,
            'last_seen' => $this->lastSeen,
            'suggestion' => $this->suggestion,
        ];
    }
}
