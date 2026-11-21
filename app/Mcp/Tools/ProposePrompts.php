<?php

namespace App\Mcp\Tools;

use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolFailure;
use App\Mcp\ToolResult;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('propose_prompts')]
#[Description('Grunnlaget for å foreslå nye prompts for et tema i et marked. Skriver ingenting. Gir promptene som finnes (tekst, intensjon, trakttrinn, prioritet), hvor mange det er per intensjon og trakttrinn, hvor mange flere pakken tillater, og reglene. Lag forslagene selv ut fra dette: på markedets språk, slik en kunde ville spurt en AI-assistent, uten merkevarens navn med mindre intensjonen er brand, og fyll hullene i dekningen. Vis forslagene til brukeren; legg dem inn med upsert_prompts bare når brukeren ber om det.')]
#[IsReadOnly]
class ProposePrompts extends EkkoTool
{
    public const INTENTS = ['category', 'comparison', 'brand', 'problem', 'local'];

    public const FUNNEL_STAGES = ['awareness', 'consideration', 'decision'];

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            'include_archived' => $schema->boolean()->description('Ta med arkiverte prompts.'),
            'market' => $schema->integer()->description('Marked-id (se list_brands).')->required(),
            'topic' => $schema->integer()->description('Tema-id (se list_brands). Tom: alle promptene i markedet.'),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $market = is_numeric($request->get('market')) ? Market::query()->with('brand:id,name')->find((int) $request->get('market')) : null;
        if ($market === null) {
            throw new ToolFailure(__('mcp.errors.unknown_market'));
        }
        $topic = null;
        if ($request->get('topic') !== null && $request->get('topic') !== '') {
            $topic = is_numeric($request->get('topic'))
                ? Topic::query()->where('brand_id', $market->brand_id)->find((int) $request->get('topic'))
                : null;
            if ($topic === null) {
                throw new ToolFailure(__('mcp.errors.unknown_topic'));
            }
        }

        $prompts = Prompt::query()->when($request->get('include_archived') !== true, fn ($q) => $q->whereNull('archived_at'))->where('market_id', $market->id)
            ->when($topic !== null, fn ($query) => $query->where('topic_id', $topic?->id))
            ->orderBy('id')->get();

        $coverage = [];
        foreach (self::INTENTS as $intent) {
            foreach (self::FUNNEL_STAGES as $stage) {
                $coverage[$intent][$stage] = $prompts->where('is_active', true)->where('intent', $intent)->where('funnel_stage', $stage)->count();
            }
        }

        $limit = $tenant->plan?->max_prompts_per_market;
        $active = Prompt::query()->where('market_id', $market->id)->where('is_active', true)->count();

        return new ToolResult(
            __('mcp.summaries.propose_prompts', ['count' => $prompts->count(), 'room' => $limit === null ? '–' : max(0, $limit - $active)]),
            [
                'brand' => ['id' => $market->brand?->id, 'name' => $market->brand?->name],
                'market' => ['id' => $market->id, 'country' => $market->country, 'language' => $market->language, 'location' => $market->location_label],
                'topic' => $topic === null ? null : ['id' => $topic->id, 'name' => $topic->name],
                'existing_prompts' => $prompts->map(fn (Prompt $prompt): array => [
                    'id' => $prompt->id,
                    'text' => $prompt->text,
                    'intent' => $prompt->intent,
                    'funnel_stage' => $prompt->funnel_stage,
                    'priority' => $prompt->priority,
                    'is_active' => (bool) $prompt->is_active,
                    'archived' => $prompt->archived_at !== null,
                ])->values()->all(),
                'active_coverage' => $coverage,
                'active_prompts_in_market' => $active,
                'max_prompts_per_market' => $limit,
                'room_for_new_prompts' => $limit === null ? null : max(0, $limit - $active),
                'allowed' => ['intent' => self::INTENTS, 'funnel_stage' => self::FUNNEL_STAGES, 'priority' => ['core', 'standard']],
            ],
        );
    }
}
