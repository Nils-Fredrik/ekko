<?php

namespace App\Onboarding\Fetch;

use RuntimeException;

/**
 * A page could not be fetched. The reason is a stable code (lang/{locale}/onboarding.php, fetch.*); the
 * message never contains the address or any of the page, so it is safe to log.
 */
final class FetchFailed extends RuntimeException
{
    public const INVALID_URL = 'invalid_url';

    public const BLOCKED_ADDRESS = 'blocked_address';

    public const DNS = 'dns';

    public const TOO_LARGE = 'too_large';

    public const TOO_MANY_REDIRECTS = 'too_many_redirects';

    public const TIMEOUT = 'timeout';

    public const UNSUPPORTED_CONTENT = 'unsupported_content';

    public const HTTP_ERROR = 'http_error';

    public const CONNECTION = 'connection';

    private function __construct(public readonly string $reason)
    {
        parent::__construct("Page fetch failed: {$reason}");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
