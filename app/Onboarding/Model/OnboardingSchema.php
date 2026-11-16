<?php

namespace App\Onboarding\Model;

/**
 * The JSON schemas the wizard model must follow (docs/spec.md 13), passed as structured output. Structured
 * output accepts no counts or length limits, so those are enforced when the reply is parsed,
 * see {@see ProposalParser}.
 */
final class OnboardingSchema
{
    public const INTENTS = ['category', 'comparison', 'brand', 'problem', 'local'];

    public const FUNNEL_STAGES = ['awareness', 'consideration', 'decision'];

    /** @return array<string, mixed> */
    public static function brand(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['industry', 'category', 'name_variants', 'competitors', 'topics', 'prompts'],
            'properties' => [
                'industry' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'name_variants' => ['type' => 'array', 'items' => ['type' => 'string']],
                'competitors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'domain'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'domain' => ['type' => 'string'],
                        ],
                    ],
                ],
                'topics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'cross_market_key'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'cross_market_key' => ['type' => 'string'],
                        ],
                    ],
                ],
                'prompts' => ['type' => 'array', 'items' => self::prompt(['type' => 'string'])],
            ],
        ];
    }

    /**
     * @param  list<string>  $topicKeys  the cross_market_key values the brand already has; the model may only use these
     * @return array<string, mixed>
     */
    public static function market(array $topicKeys): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['prompts'],
            'properties' => [
                'prompts' => ['type' => 'array', 'items' => self::prompt(['type' => 'string', 'enum' => $topicKeys])],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $topicKey  the schema of the field that names the topic
     * @return array<string, mixed>
     */
    private static function prompt(array $topicKey): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['text', 'cross_market_key', 'intent', 'funnel_stage', 'is_core'],
            'properties' => [
                'text' => ['type' => 'string'],
                'cross_market_key' => $topicKey,
                'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                'funnel_stage' => ['type' => 'string', 'enum' => self::FUNNEL_STAGES],
                'is_core' => ['type' => 'boolean'],
            ],
        ];
    }
}
