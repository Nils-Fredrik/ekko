<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UsageMonthlyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageMonthly extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UsageMonthlyFactory> */
    use HasFactory;

    protected $table = 'usage_monthly';

    /** @var list<string> */
    protected $fillable = [
        'month',
        'calls',
        'cost_usd',
        'quota_calls',
        'budget_usd',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'cost_usd' => 'decimal:8',
            'budget_usd' => 'decimal:2',
        ];
    }
}
