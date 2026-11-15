<?php

namespace App\Mcp;

/**
 * What a tool returns (docs/spec.md 15.2): structured JSON and a short text summary.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly string $summary, public readonly array $data) {}
}
