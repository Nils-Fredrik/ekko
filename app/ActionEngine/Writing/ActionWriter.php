<?php

namespace App\ActionEngine\Writing;

/**
 * Words an action with Claude Sonnet 5.5 (docs/spec.md 11.3). Bound to AnthropicActionWriter; tests bind a fake,
 * so no test makes a paid call.
 */
interface ActionWriter
{
    /**
     * @throws ActionWriterFailed
     */
    public function write(ActionTextRequest $request): ActionWriterResult;
}
