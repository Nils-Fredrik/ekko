<?php

namespace App\Http\Resources\Api;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->only('id', 'alert_rule_id', 'triggered_at', 'payload', 'delivered_at', 'acknowledged_by');
    }
}
