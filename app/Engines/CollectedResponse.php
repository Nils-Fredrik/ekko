<?php

namespace App\Engines;

use App\Models\Response;

final readonly class CollectedResponse
{
    /**
     * The result carries provider annotations for extraction after a new collection.
     * A replay returns the stored response without calling the provider again.
     */
    public function __construct(
        public Response $response,
        public ?EngineResult $result,
    ) {}
}
