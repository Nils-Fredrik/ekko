<?php

namespace App\ActionEngine;

use App\ActionEngine\Writing\ActionTextRequest;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Prompt;
use App\Models\Team;

/**
 * The request that asks Claude Sonnet 5.5 to word one action (docs/spec.md 11.3). The model gets the evidence
 * read from the database and writes title, description and steps without any figure: the figures are shown next
 * to the text from evidence, so they are never the model's (ActionTextGuard enforces it).
 */
final class ActionPromptBuilder
{
    public const MAX_TITLE = 120;

    public const MAX_DESCRIPTION = 700;

    public const MAX_STEP = 240;

    public function build(Action $action, Brand $brand): ActionTextRequest
    {
        // The brand's language is the market's (or the tenant's for a brand-level action), never the template locale.
        $tag = self::languageTag($action);
        $language = ActionLocale::languageName($tag);
        $promptTexts = Prompt::query()->whereIn('id', $action->prompts()->limit(5)->pluck('prompts.id'))
            ->orderBy('id')->pluck('text')->map(fn ($text): string => mb_substr((string) $text, 0, 300))->all();
        $topic = $action->topic?->name;
        $market = $action->market;

        $names = array_values(array_filter([
            $brand->name,
            (string) $brand->primary_domain,
            (string) $topic,
            ...array_map(fn (array $c): string => (string) ($c['name'] ?? ''), $action->competitor_evidence),
            ...array_map(fn ($value): string => is_string($value) ? $value : '', $action->evidence['text_params'] ?? []),
            ...array_filter([$action->evidence['domain'] ?? null, $action->evidence['name'] ?? null, $action->evidence['user_agent'] ?? null], 'is_string'),
            ...$promptTexts,
        ], fn (string $name): bool => trim($name) !== ''));

        $system = implode("\n", [
            'You write one recommended action for a brand whose visibility in AI search answers (ChatGPT, Gemini, Claude, Perplexity and others) Ekko measures.',
            "Write in {$language}. Address the brand's own team directly and concretely.",
            'Use only the evidence you are given. Do not invent facts, sources, people or results.',
            'Never write a number, percentage, count, date or year: the figures are shown next to your text from the database. Names that contain digits may be written exactly as given.',
            'Return JSON with:',
            '- title: an imperative headline of at most '.self::MAX_TITLE.' characters;',
            '- description: one to three sentences of at most '.self::MAX_DESCRIPTION.' characters in all, on why this matters and what to do;',
            '- steps: two to five concrete steps of at most '.self::MAX_STEP.' characters each, without numbering.',
        ]);

        $payload = [
            'action_type' => $action->type?->value,
            'brand' => ['name' => $brand->name, 'domain' => $brand->primary_domain],
            'market' => $market === null ? null : ['country' => $market->country, 'language' => $market->language],
            'topic' => $topic,
            'engines' => $action->engine_keys,
            'evidence' => array_diff_key($action->evidence, ['text_params' => true]),
            'competitor_evidence' => $action->competitor_evidence,
            'example_prompts' => $promptTexts,
        ];

        return new ActionTextRequest(
            $system,
            "Evidence (JSON):\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            self::schema(),
            $names,
        );
    }

    /** The language tag the model writes the action in. */
    public static function languageTag(Action $action): string
    {
        return ActionLocale::tag($action->market, Team::query()->find(current_tenant_id()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'description', 'steps'],
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
