<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $brand_id
 * @property string $cadence
 * @property list<string> $recipients
 * @property string $locale
 * @property string $branding
 */
class ReportSchedule extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportScheduleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'cadence',
        'recipients',
        'locale',
        'branding',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
        ];
    }
}
