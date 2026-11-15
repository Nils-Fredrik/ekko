<?php

namespace App\Http\Resources\Api;

use App\Models\UsageMonthly;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UsageMonthly */
class UsageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->only('month', 'calls', 'cost_usd', 'quota_calls', 'budget_usd', 'byok_cost_usd', 'byok_budget_usd', 'status');
    }
}
