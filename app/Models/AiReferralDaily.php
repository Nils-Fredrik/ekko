<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AiReferralDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $date */
class AiReferralDaily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AiReferralDailyFactory> */
    use HasFactory;

    protected $table = 'ai_referrals_daily';

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'source',
        'date',
        'sessions',
        'engaged_sessions',
        'conversions',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'conversions' => 'decimal:4',
        ];
    }
}
