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
        // position: the color order of the workspace (docs/ux-arbeidsflate.md 6.3).
        return $this->resource->only('id', 'brand_id', 'name', 'is_active', 'position');
    }
}
