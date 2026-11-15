<?php

namespace App\Mcp;

use RuntimeException;

/**
 * A tool call that cannot be done, with a message for Claude (already translated).
 */
final class ToolFailure extends RuntimeException {}
