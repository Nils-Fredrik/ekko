<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A reserved monthly Sonnet allowance for the report producer (T32), never a generated insight. */
class AnthropicInsightAllocation extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['brand_id', 'cycle_start', 'cost_usd', 'consumed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cycle_start' => 'immutable_date', 'cost_usd' => 'decimal:8', 'consumed_at' => 'immutable_datetime'];
    }
}
