<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UsageMonthlyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $month
 * @property int $calls
 * @property string $cost_usd
 * @property int|null $quota_calls
 * @property string|null $budget_usd
 * @property string $byok_cost_usd
 * @property string|null $byok_budget_usd
 * @property int $quota_alerted_threshold
 * @property string $status
 */
class UsageMonthly extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UsageMonthlyFactory> */
    use HasFactory;

    protected $table = 'usage_monthly';

    protected $attributes = [
        'calls' => 0,
        'cost_usd' => 0,
        'byok_cost_usd' => 0,
        'quota_alerted_threshold' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'month',
        'calls',
        'cost_usd',
        'quota_calls',
        'budget_usd',
        'byok_cost_usd',
        'byok_budget_usd',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'calls' => 'integer',
            'quota_calls' => 'integer',
            'quota_alerted_threshold' => 'integer',
            'cost_usd' => 'decimal:8',
            'byok_cost_usd' => 'decimal:8',
            'byok_budget_usd' => 'decimal:2',
            'budget_usd' => 'decimal:2',
        ];
    }
}
