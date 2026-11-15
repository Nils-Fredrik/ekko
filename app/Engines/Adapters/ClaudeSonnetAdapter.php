<?php

namespace App\Engines\Adapters;

final class ClaudeSonnetAdapter extends ClaudeMessagesAdapter
{
    public function key(): string
    {
        return 'claude_sonnet';
    }

    protected function defaultModel(): string
    {
        return 'claude-sonnet-5-5';
    }
}
