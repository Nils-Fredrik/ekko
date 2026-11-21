<?php

namespace App\Http\Resources\Api;

use App\Models\Prompt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Prompt */
class PromptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->only('id', 'market_id', 'topic_id', 'text', 'intent', 'funnel_stage', 'priority', 'is_active', 'created_at', 'updated_at') + ['archived' => $this->archived_at !== null];
    }
}
