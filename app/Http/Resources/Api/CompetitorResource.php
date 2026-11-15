<?php

namespace App\Http\Resources\Api;

use App\Models\Competitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Competitor */
class CompetitorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->only('id', 'brand_id', 'name', 'is_active');
    }
}
