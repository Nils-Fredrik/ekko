<?php

namespace App\Api;

use App\Models\Action;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use App\Models\Report;
use App\Models\Team;

/** Explicit public fields: never expose storage paths, recipients, credentials or model internals. */
final class ProductData
{
    /** @return array<string, mixed> */
    public static function action(Action $action): array
    {
        return $action->only(['id', 'brand_id', 'market_id', 'topic_id', 'type', 'status', 'title', 'description',
            'steps', 'evidence', 'competitor_evidence', 'engine_keys', 'impact_level', 'created_by', 'text_source',
            'started_at', 'completed_at']) + ['prompt_ids' => $action->prompts->pluck('id')->all()];
    }

    /** @return array<string, mixed> */
    public static function fact(BrandFact $fact): array
    {
        return $fact->only(['id', 'brand_id', 'text', 'is_active', 'created_at', 'updated_at']);
    }

    /** @return array<string, mixed> */
    public static function claim(Claim $claim): array
    {
        return $claim->only(['id', 'brand_id', 'response_id', 'fact_id', 'verdict']) + [
            'untrusted_engine_output' => [
                'text' => $claim->text, 'quote' => $claim->quote,
                'sources' => Citation::query()->where('response_id', $claim->response_id)
                    ->whereIn('id', $claim->source_citation_ids)->orderBy('id')->get(['id', 'url', 'domain', 'ownership', 'source_type'])->toArray(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function report(Report $report): array
    {
        return [
            'id' => $report->id,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'sent_at' => $report->sent_at?->toIso8601String(),
            'content' => $report->web_snapshot === null ? null : array_intersect_key($report->web_snapshot, array_flip([
                'locale', 'brand', 'from', 'to', 'insight', 'headlines', 'responses', 'trend', 'engines', 'prompts', 'competitors', 'sources', 'gaps', 'bots', 'traffic',
            ])),
            'actions' => $report->action_section,
            // This is an authenticated application URL, not a public object-storage URL.
            'pdf_url' => $report->pdf_uri ? route('reports.download', $report) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function pitch(Team $pitch): array
    {
        return $pitch->only(['id', 'name', 'kind', 'partner_team_id', 'pitch_expires_at', 'pitch_converted_at', 'status']);
    }
}
