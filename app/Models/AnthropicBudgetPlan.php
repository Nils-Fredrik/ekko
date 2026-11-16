<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** @property list<array{key: string, kind: string, cost: int, tenant: int, prompt: int, engine: string, repetition: int}> $additions */
class AnthropicBudgetPlan extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['day', 'additions'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['day' => 'immutable_date', 'additions' => 'array'];
    }
}
