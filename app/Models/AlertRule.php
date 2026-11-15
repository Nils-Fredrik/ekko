<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    use RecordsRevisions;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'type',
        'threshold',
        'channels',
        'is_active',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:4',
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['brand_id', 'type', 'threshold', 'is_active'];
    }

    /** @return list<string> */
    protected function maskedRevisionAttributes(): array
    {
        return ['channels'];
    }
}
