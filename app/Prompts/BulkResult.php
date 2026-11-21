<?php

namespace App\Prompts;

final readonly class BulkResult
{
    /** @param array{action: string, ids: list<int>, value: string}|null $undo */
    public function __construct(public int $affected, public ?array $undo) {}
}
