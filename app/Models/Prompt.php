<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Carbon\CarbonImmutable;
use Database\Factories\PromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** @property CarbonImmutable|null $archived_at */
class Prompt extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PromptFactory> */
    use HasFactory;

    use RecordsRevisions;

    /** @var list<string> */
    public const INTENTS = ['category', 'comparison', 'brand', 'problem', 'local'];

    /** @var list<string> */
    public const FUNNELS = ['awareness', 'consideration', 'decision'];

    /** @var list<string> */
    public const PRIORITIES = ['core', 'standard'];

    /**
     * The same limit on every way a prompt is saved, so a prompt created through the API can still be edited.
     */
    public const TEXT_MAX = 10000;

    public static function hashText(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * A regular expression cannot carry a NUL byte, so the check is a string search.
     */
    public static function nullByteRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail('validation.regex')->translate(['attribute' => $attribute]);
            }
        };
    }

    /** @var list<string> */
    protected $fillable = [
        'market_id',
        'topic_id',
        'text',
        'intent',
        'funnel_stage',
        'priority',
        'archived_at',
        'is_active',
        'text_hash',
    ];

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

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** @return HasManyThrough<Response, Run, $this> */
    public function responses(): HasManyThrough
    {
        return $this->hasManyThrough(Response::class, Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['market_id', 'topic_id', 'intent', 'funnel_stage', 'priority', 'is_active', 'archived_at'];
    }

    /** @return list<string> */
    protected function maskedRevisionAttributes(): array
    {
        return ['text'];
    }
}
