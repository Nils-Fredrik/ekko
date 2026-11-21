<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the tenant decided about one unknown brand name of a brand (docs/ux-arbeidsflate.md 6.3, ADR 0043): a
 * competitor, another actor, an alias of the brand or a competitor, or ignored. One per brand and name key.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name_key
 * @property string $display_name
 * @property list<string> $variants
 * @property string $decision
 * @property string|null $target_type
 * @property int|null $target_id
 * @property bool $created_competitor
 * @property list<int> $alias_ids
 * @property int $reclassified_mentions
 * @property int|null $decided_by
 * @property Carbon $decided_at
 */
class EntityDecision extends Model
{
    use BelongsToTenant;

    public const COMPETITOR = 'competitor';

    public const OTHER = 'other';

    public const ALIAS = 'alias';

    public const IGNORED = 'ignored';

    public const DECISIONS = [self::COMPETITOR, self::OTHER, self::ALIAS, self::IGNORED];

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'name_key',
        'display_name',
        'variants',
        'decision',
        'target_type',
        'target_id',
        'created_competitor',
        'alias_ids',
        'reclassified_mentions',
        'decided_by',
        'decided_at',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Whether the decision can still be undone (docs/ux-arbeidsflate.md 6.3: 24 hours). */
    public function undoable(): bool
    {
        return $this->decided_at->greaterThan(now()->subHours((int) config('ekko.entities.undo_hours')));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'variants' => 'array',
            'target_id' => 'integer',
            'created_competitor' => 'boolean',
            'alias_ids' => 'array',
            'reclassified_mentions' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
