<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekly action generation of a brand (ADR 0034): unique per brand and tenant-local ISO week, so a second
 * run in the same week does not count towards expiry twice.
 *
 * @property int $brand_id
 * @property CarbonInterface $week_start
 * @property CarbonInterface $generated_at
 */
class ActionGeneration extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['brand_id', 'week_start', 'generated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['week_start' => 'date', 'generated_at' => 'datetime'];
    }
}
