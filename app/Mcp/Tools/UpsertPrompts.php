<?php

namespace App\Mcp\Tools;

use App\Enums\TenantPermission;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolFailure;
use App\Mcp\ToolResult;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Tenancy\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert_prompts')]
#[Description('Legger til eller endrer prompts (høyst 25 per kall). Med id endres promptet (et prompt kan ikke flyttes til et annet marked); uten id legges det til i markedet, eller det som har samme tekst i markedet, endres. Krever at brukeren tillot endringer ved tilkoblingen og har rett til å redigere prompts (admin, partnerrådgiver eller redaktør). Endringene logges med brukerens navn. Bruk bare når brukeren ber om det, og vis først nøyaktig hva som legges til eller endres.')]
#[IsIdempotent]
class UpsertPrompts extends EkkoTool
{
    public const MAX_ITEMS = 25;

    protected ?TenantPermission $permission = TenantPermission::ManagePrompts;

    protected bool $writes = true;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            'prompts' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->description('Promptet som endres. Tom: nytt prompt.'),
                'market' => $schema->integer()->description('Marked-id. Påkrevd for nye prompts. Kan ikke endres for et prompt som finnes.'),
                'text' => $schema->string()->description('Spørsmålet, på markedets språk. Påkrevd for nye prompts.'),
                'topic' => $schema->integer()->nullable()->description('Tema-id, eller null for å fjerne temaet. Utelatt: uendret.'),
                'intent' => $schema->string()->enum(ProposePrompts::INTENTS)->description('Påkrevd for nye prompts.'),
                'funnel_stage' => $schema->string()->enum(ProposePrompts::FUNNEL_STAGES)->description('Påkrevd for nye prompts.'),
                'priority' => $schema->string()->enum(['core', 'standard'])->description('Standard: standard.'),
                'is_active' => $schema->boolean()->description('Standard: true.'),
            ]))->description('Promptene som legges til eller endres.')->required(),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $items = Validator::make(['prompts' => $request->get('prompts')], [
            'prompts' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'prompts.*' => ['array'],
            'prompts.*.id' => ['nullable', 'integer'],
            'prompts.*.market' => ['nullable', 'integer', 'required_without:prompts.*.id'],
            'prompts.*.text' => ['nullable', 'string', 'max:2000', 'required_without:prompts.*.id'],
            'prompts.*.topic' => ['nullable', 'integer'],
            'prompts.*.intent' => ['nullable', Rule::in(ProposePrompts::INTENTS), 'required_without:prompts.*.id'],
            'prompts.*.funnel_stage' => ['nullable', Rule::in(ProposePrompts::FUNNEL_STAGES), 'required_without:prompts.*.id'],
            'prompts.*.priority' => ['nullable', Rule::in(['core', 'standard'])],
            'prompts.*.is_active' => ['nullable', 'boolean'],
        ])->validate()['prompts'];

        // Lock the markets first, in id order, so concurrent calls cannot both pass the plan limit.
        $this->lockMarkets($items);

        $gate = Gate::forUser($context->user);
        $created = [];
        $updated = [];
        /** @var array<int, int> $activeBefore market id => active prompts before the call */
        $activeBefore = [];

        foreach (array_values($items) as $index => $item) {
            $prompt = isset($item['id'])
                ? (Prompt::query()->find((int) $item['id']) ?? throw new ToolFailure(__('mcp.errors.unknown_prompt_item', ['index' => $index + 1])))
                : null;
            // A prompt keeps its market: its answers and aggregates belong to that market and brand.
            if ($prompt !== null && isset($item['market']) && (int) $item['market'] !== $prompt->market_id) {
                throw new ToolFailure(__('mcp.errors.cannot_move', ['index' => $index + 1]));
            }
            $market = $this->market($item, $prompt, $index);
            $activeBefore[$market->id] ??= $this->activeIn($market->id);
            $text = array_key_exists('text', $item) && $item['text'] !== null ? trim((string) $item['text']) : null;
            if ($text === '') {
                throw new ToolFailure(__('mcp.errors.empty_text', ['index' => $index + 1]));
            }

            // Without an id, a prompt with the same text in the market is changed instead of duplicated.
            if ($prompt === null && $text !== null) {
                $prompt = Prompt::query()->where('market_id', $market->id)->where('text_hash', self::hash($text))->first();
            }

            $attributes = array_filter([
                'market_id' => $market->id,
                'text' => $text,
                'text_hash' => $text === null ? null : self::hash($text),
                'intent' => $item['intent'] ?? null,
                'funnel_stage' => $item['funnel_stage'] ?? null,
                'priority' => $item['priority'] ?? null,
                'is_active' => array_key_exists('is_active', $item) && $item['is_active'] !== null ? (bool) $item['is_active'] : null,
            ], fn ($value) => $value !== null);
            if (array_key_exists('topic', $item)) {
                $attributes['topic_id'] = $this->topicId($item['topic'], $market, $index);
            }

            if ($prompt === null) {
                if (! $gate->allows('create', Prompt::class)) {
                    throw new ToolFailure(__('mcp.errors.forbidden'));
                }
                $prompt = Prompt::query()->create(['priority' => 'standard', 'is_active' => true, ...$attributes]);
                $created[] = $prompt->id;
            } else {
                if (! $gate->allows('update', $prompt)) {
                    throw new ToolFailure(__('mcp.errors.forbidden'));
                }
                // The same text may only be in a market once.
                $hash = $text === null ? $prompt->text_hash : self::hash($text);
                if (Prompt::query()->where('market_id', $market->id)->where('text_hash', $hash)->whereKeyNot($prompt->id)->exists()) {
                    throw new ToolFailure(__('mcp.errors.duplicate_text', ['index' => $index + 1]));
                }
                $prompt->fill($attributes)->save();
                if ($prompt->wasChanged() && ! in_array($prompt->id, $created, true)) {
                    $updated[] = $prompt->id;
                }
            }
        }

        $this->checkPlanLimit($tenant, $activeBefore);

        $updated = array_values(array_unique(array_diff($updated, $created)));
        app(AuditLog::class)->mcpChange($context->user, $tenant, $this->name(), [
            'created' => count($created),
            'updated' => count($updated),
            'prompt_ids' => [...$created, ...$updated],
            'mcp_connection_id' => $context->connection?->id,
        ]);

        return new ToolResult(
            __('mcp.summaries.upsert_prompts', ['created' => count($created), 'updated' => count($updated)]),
            ['created' => $created, 'updated' => $updated],
        );
    }

    /**
     * Locks every market the call touches (FOR UPDATE, in id order to avoid deadlocks). Concurrent calls for the
     * same market then run one after the other, and the plan limit is checked on committed counts.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function lockMarkets(array $items): void
    {
        $ids = [];
        $promptIds = [];
        foreach ($items as $item) {
            if (isset($item['market'])) {
                $ids[] = (int) $item['market'];
            }
            if (isset($item['id'])) {
                $promptIds[] = (int) $item['id'];
            }
        }
        if ($promptIds !== []) {
            $ids = [...$ids, ...Prompt::query()->whereIn('id', $promptIds)->pluck('market_id')->map(fn ($id): int => (int) $id)->all()];
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids !== []) {
            Market::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /**
     * The market of the item: the argument for new prompts, or the prompt's own.
     *
     * @param  array<string, mixed>  $item
     */
    private function market(array $item, ?Prompt $prompt, int $index): Market
    {
        $id = $item['market'] ?? $prompt?->market_id;
        $market = $id === null ? null : Market::query()->find((int) $id);
        if ($market === null) {
            throw new ToolFailure(__('mcp.errors.unknown_market_item', ['index' => $index + 1]));
        }

        return $market;
    }

    private function topicId(mixed $topic, Market $market, int $index): ?int
    {
        if ($topic === null || $topic === '') {
            return null;
        }
        $found = Topic::query()->where('brand_id', $market->brand_id)->find((int) $topic);
        if ($found === null) {
            throw new ToolFailure(__('mcp.errors.unknown_topic_item', ['index' => $index + 1]));
        }

        return $found->id;
    }

    /**
     * The plan's limit on active prompts per market (docs/spec.md 6.1). The call is refused when it adds active
     * prompts beyond the limit. A market already over the limit (after a change to a smaller plan) can still be
     * edited, as long as the call does not add active prompts there.
     *
     * @param  array<int, int>  $activeBefore  market id => active prompts before the call
     */
    private function checkPlanLimit(Team $tenant, array $activeBefore): void
    {
        $limit = $tenant->plan?->max_prompts_per_market;
        if ($limit === null) {
            return;
        }
        foreach ($activeBefore as $marketId => $before) {
            $after = $this->activeIn($marketId);
            if ($after > $limit && $after > $before) {
                throw new ToolFailure(__('mcp.errors.plan_limit', ['limit' => $limit]));
            }
        }
    }

    private function activeIn(int $marketId): int
    {
        return Prompt::query()->where('market_id', $marketId)->where('is_active', true)->count();
    }

    /** The text hash that keeps a prompt unique in its market. */
    public static function hash(string $text): string
    {
        return hash('sha256', trim($text));
    }
}
