<?php

namespace App\Extraction;

/**
 * The JSON schema the model must follow (docs/spec.md 8.2), passed as structured output
 * (output_config.format). Structured outputs accept no numeric or length bounds, so the ranges
 * (sentiment, evidence and quote length, list position) are enforced when the answer is parsed,
 * see {@see ExtractionResult}.
 */
final class ExtractionSchema
{
    public const ENTITY_TYPES = ['brand', 'competitor', 'unknown'];

    public const SOURCE_TYPES = ['news', 'review', 'forum', 'wiki', 'marketplace', 'social', 'gov', 'other'];

    public const ANSWER_TYPES = ['list', 'single_recommendation', 'comparison', 'informational', 'refusal'];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['mentions', 'citations', 'answer_type'],
            'properties' => [
                'mentions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['surface_name', 'entity', 'list_position', 'is_recommended', 'sentiment', 'evidence', 'sentiment_quote'],
                        'properties' => [
                            'surface_name' => ['type' => 'string'],
                            'entity' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['type', 'id'],
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => self::ENTITY_TYPES],
                                    'id' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                                ],
                            ],
                            'list_position' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                            'is_recommended' => ['type' => 'boolean'],
                            'sentiment' => ['type' => 'number'],
                            'evidence' => ['type' => 'string'],
                            'sentiment_quote' => ['type' => 'string'],
                        ],
                    ],
                ],
                'citations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['url', 'source_type'],
                        'properties' => [
                            'url' => ['type' => 'string'],
                            'source_type' => ['type' => 'string', 'enum' => self::SOURCE_TYPES],
                        ],
                    ],
                ],
                'answer_type' => ['type' => 'string', 'enum' => self::ANSWER_TYPES],
            ],
        ];
    }
}
