<?php

namespace App\Engines\Adapters;

final class ClaudeHaikuAdapter extends ClaudeMessagesAdapter
{
    public function key(): string
    {
        return 'claude_haiku';
    }

    protected function defaultModel(): string
    {
        return 'claude-haiku-5-5';
    }
}
