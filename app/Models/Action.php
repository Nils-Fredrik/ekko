<?php

namespace App\Models;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Carbon\CarbonInterface;
use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An action for a brand (docs/spec.md 11.3, ADR 0034). The numbers in evidence and competitor_evidence come from
 * the database; only title, description and the step texts are written by the model (or a template).
 *
 * started_at and completed_at are the dates of the action markers in the trend charts (T45): set when the
 * action moves to in_progress (first time) and done. Change the status through ActionWorkflow, not directly.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $brand_id
 * @property int|null $market_id
 * @property ActionType|null $type
 * @property int|null $topic_id
 * @property string $title
 * @property string $description
 * @property list<array{text: string, done: bool}> $steps
 * @property array<string, mixed> $evidence
 * @property list<array<string, mixed>> $competitor_evidence
 * @property list<string> $engine_keys
 * @property int $impact_level
 * @property string $impact_score
 * @property ActionStatus $status
 * @property string $created_by
 * @property string $text_source
 * @property string $locale
 * @property string|null $fingerprint
 * @property int $unsupported_generations
 * @property CarbonInterface|null $last_generated_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Action extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ActionFactory> */
    use HasFactory;

    use RecordsRevisions;

    public const CREATED_BY_SYSTEM = 'system';

    public const CREATED_BY_USER = 'user';

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'market_id',
        'type',
        'topic_id',
        'title',
        'description',
        'steps',
        'evidence',
        'competitor_evidence',
        'engine_keys',
        'impact_level',
        'impact_score',
        'status',
        'created_by',
        'text_source',
        'locale',
        'fingerprint',
        'unsupported_generations',
        'last_generated_at',
        'started_at',
        'completed_at',
    ];

    /** Whether the user has ticked off a step: the steps are then the user's and are not replaced. */
    public function hasStepsDone(): bool
    {
        return array_filter($this->steps, fn (array $step): bool => $step['done']) !== [];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * The prompts the action concerns (action_prompts). Read through this relation; ActionPrompt writes the rows,
     * so tenant_id is set from the context.
     *
     * @return BelongsToMany<Prompt, $this>
     */
    public function prompts(): BelongsToMany
    {
        return $this->belongsToMany(Prompt::class, 'action_prompts')->orderBy('prompts.id');
    }

    /**
     * Open actions: new, planned and in_progress.
     *
     * @param  Builder<Action>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (ActionStatus $s): string => $s->value, ActionStatus::open()));
    }

    /**
     * The ranking of docs/spec.md 11.3, per brand: fact_correction first within the brand, then expected effect
     * (level, then the score behind it), then the oldest first so the order is stable.
     *
     * @param  Builder<Action>  $query
     */
    public function scopeRanked(Builder $query): void
    {
        $query->orderBy('actions.brand_id')
            ->orderByRaw("CASE WHEN actions.type = 'fact_correction' THEN 0 ELSE 1 END")
            ->orderByDesc('actions.impact_level')
            ->orderByDesc('actions.impact_score')
            ->orderBy('actions.id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ActionType::class,
            'status' => ActionStatus::class,
            'steps' => 'array',
            'evidence' => 'array',
            'competitor_evidence' => 'array',
            'engine_keys' => 'array',
            'impact_level' => 'integer',
            'impact_score' => 'decimal:2',
            'unsupported_generations' => 'integer',
            'last_generated_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['brand_id', 'market_id', 'type', 'status', 'created_by', 'started_at', 'completed_at'];
    }

    /** @return list<string> */
    protected function maskedRevisionAttributes(): array
    {
        // Free text: logged as changed, never with its value.
        return ['title'];
    }
}
