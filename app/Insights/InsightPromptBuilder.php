<?php

namespace App\Insights;

use App\ActionEngine\ActionLocale;
use App\ActionEngine\Writing\ActionTextRequest;
use App\Dashboard\DashboardPeriod;

/**
 * The request that asks Claude Sonnet 5.5 for the insight text (docs/spec.md 14.2, ADR 0038). The model gets the
 * findings with directions and placeholder names, never the figures: it writes a template and the code fills in the
 * numbers from the database (InsightTextGuard enforces it).
 */
final class InsightPromptBuilder
{
    public const BRAND_MAX_LENGTH = 320;

    public const OVERVIEW_MAX_LENGTH = 480;

    public function build(InsightFigures $figures, string $scope, string $locale, DashboardPeriod $period): ActionTextRequest
    {
        $language = ActionLocale::languageName($locale);
        $max = $scope === 'overview' ? self::OVERVIEW_MAX_LENGTH : self::BRAND_MAX_LENGTH;
        $sentences = $scope === 'overview' ? 'two or three sentences' : 'one or two sentences';
        $subject = $scope === 'overview' ? 'what changed across the brands a tenant monitors' : 'what changed for one brand';

        $system = implode("\n", [
            "You write the insight line on a dashboard for brands whose visibility in AI search answers (ChatGPT, Gemini, Claude, Perplexity and others) Ekko measures. It summarises {$subject}.",
            "Write in {$language}, in {$sentences} of plain text, at most {$max} characters in all, without markdown or headings.",
            'Use only the findings you are given, most important first. Do not mention anything that is not in the findings. Do not give advice and do not invent causes.',
            'Each finding has a direction (up, down or unchanged) and placeholders. Show a figure only by writing its placeholder exactly as given in curly braces, for example {visibility_change}. The code fills the placeholders in from the database. A change placeholder is the size of the change, so say whether it went up or down in words.',
            'Never write a digit, number, percentage, count, date or year, and never a number in words. Names (brand, engines, competitors, prompts, models) may be written exactly as given.',
            'A finding with a direction but without a change placeholder is a real movement whose size is not shown: describe it by its direction and name only. A finding with a note that the earlier period has too few answers has too little data to describe a change: state the current level at most, or leave it out.',
            'Return JSON with a single field, text.',
        ]);

        $payload = [
            'scope' => $scope,
            'period_days' => $period->days,
            'period' => ['from' => $period->from, 'to' => $period->to],
            'comparison_mode' => $period->comparison,
            'comparison' => $period->comparison === 'year' ? 'the same calendar dates last year' : "the {$period->days} days before the period",
            'comparison_period' => ['from' => $period->previousFrom, 'to' => $period->previousTo],
            'findings' => array_map(fn (array $finding): array => array_diff_key($finding, ['id' => true]), $figures->findings),
        ];

        return new ActionTextRequest(
            $system,
            "Findings (JSON):\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'], 'additionalProperties' => false],
            $figures->names,
        );
    }

    public function maxLength(string $scope): int
    {
        return $scope === 'overview' ? self::OVERVIEW_MAX_LENGTH : self::BRAND_MAX_LENGTH;
    }
}
