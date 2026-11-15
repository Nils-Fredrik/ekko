<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Database\Factories\PromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<PromptFactory> */
    use HasFactory;

    use RecordsRevisions;

    /** @var list<string> */
    protected $fillable = [
        'market_id',
        'topic_id',
        'text',
        'intent',
        'funnel_stage',
        'priority',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['market_id', 'topic_id', 'intent', 'funnel_stage', 'priority', 'is_active'];
    }

    /** @return list<string> */
    protected function maskedRevisionAttributes(): array
    {
        return ['text'];
    }
}
