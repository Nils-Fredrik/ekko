<?php

namespace App\Http\Resources\Api;

use App\Models\Response;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Response */
class ResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->only('id', 'run_id', 'engine_key', 'model_id_reported', 'answer_text', 'answer_language', 'collected_at', 'answer_type', 'llm_failed'),
            'prompt_id' => $this->run->prompt_id,
            'mentions' => $this->mentions->map(fn ($mention) => $mention->only('entity_type', 'entity_id', 'surface_name', 'first_char_offset', 'list_position', 'is_recommended', 'sentiment', 'detection')),
            'citations' => $this->citations->map(fn ($citation) => $citation->only('url', 'url_normalized', 'domain', 'ownership', 'source_type', 'rank_in_answer', 'from_annotation')),
        ];
    }
}
