<?php

namespace App\Extraction;

/**
 * The model's answer, validated against the schema in docs/spec.md 8.2. {@see self::fromJson()} returns null
 * for anything that is not valid JSON of the right shape; that is what triggers the single retry. Values
 * that are only out of range are corrected instead (sentiment is clamped to -1..1, evidence cut to 200
 * characters, an unknown source type becomes "other"), because the provider cannot enforce bounds.
 */
final class ExtractionResult
{
    private const MAX_MENTIONS = 100;

    private const MAX_CITATIONS = 200;

    private const MAX_EVIDENCE = 200;

    private const MAX_SURFACE = 255;

    /**
     * @param  list<ModelMention>  $mentions
     * @param  list<ModelCitation>  $citations
     */
    public function __construct(
        public readonly array $mentions,
        public readonly array $citations,
        public readonly string $answerType,
    ) {}

    public static function fromJson(?string $text): ?self
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $data = json_decode($text, true);
        if (! is_array($data) || ! isset($data['mentions'], $data['citations'], $data['answer_type'])) {
            return null;
        }
        if (! is_array($data['mentions']) || ! array_is_list($data['mentions']) || ! is_array($data['citations']) || ! array_is_list($data['citations'])) {
            return null;
        }
        if (! is_string($data['answer_type']) || ! in_array($data['answer_type'], ExtractionSchema::ANSWER_TYPES, true)) {
            return null;
        }

        $mentions = [];
        foreach (array_slice($data['mentions'], 0, self::MAX_MENTIONS) as $row) {
            $mention = self::mention($row);
            if ($mention === null) {
                return null;
            }
            $mentions[] = $mention;
        }

        $citations = [];
        foreach (array_slice($data['citations'], 0, self::MAX_CITATIONS) as $row) {
            if (! is_array($row) || ! isset($row['url']) || ! is_string($row['url']) || trim($row['url']) === '') {
                return null;
            }
            $sourceType = $row['source_type'] ?? null;
            $citations[] = new ModelCitation(
                trim($row['url']),
                is_string($sourceType) && in_array($sourceType, ExtractionSchema::SOURCE_TYPES, true) ? $sourceType : 'other',
            );
        }

        return new self($mentions, $citations, $data['answer_type']);
    }

    private static function mention(mixed $row): ?ModelMention
    {
        if (! is_array($row) || ! isset($row['surface_name'], $row['entity'], $row['is_recommended'], $row['sentiment'])) {
            return null;
        }
        if (! is_string($row['surface_name']) || trim($row['surface_name']) === '' || ! is_array($row['entity'])) {
            return null;
        }
        if (! is_bool($row['is_recommended']) || (! is_int($row['sentiment']) && ! is_float($row['sentiment']))) {
            return null;
        }

        $type = $row['entity']['type'] ?? null;
        if (! is_string($type) || ! in_array($type, ExtractionSchema::ENTITY_TYPES, true)) {
            return null;
        }

        $id = $row['entity']['id'] ?? null;
        if ($type === 'unknown') {
            $id = null;
        } elseif (! is_int($id)) {
            return null;
        }

        $position = $row['list_position'] ?? null;
        if ($position !== null && ! is_int($position)) {
            return null;
        }

        $evidence = $row['evidence'] ?? '';

        return new ModelMention(
            mb_substr(trim($row['surface_name']), 0, self::MAX_SURFACE),
            $type,
            $id,
            $position !== null && $position >= 1 ? $position : null,
            $row['is_recommended'],
            max(-1.0, min(1.0, (float) $row['sentiment'])),
            is_string($evidence) ? mb_substr($evidence, 0, self::MAX_EVIDENCE) : '',
        );
    }
}
