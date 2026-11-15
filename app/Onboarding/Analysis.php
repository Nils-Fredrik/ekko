<?php

namespace App\Onboarding;

/**
 * One wizard analysis: the input, how far it has come and, when ready, the proposal (docs/spec.md 13).
 * It lives only in the cache for ekko.wizard.proposal_ttl_hours and is never written to the database, so
 * nothing the model proposed is saved before the user confirms (T25). It holds no site content, only the
 * proposal, the addresses of the pages that were read and codes.
 */
final class Analysis
{
    public const BRAND = 'brand';

    public const MARKET = 'market';

    public const QUEUED = 'queued';

    public const FETCHING = 'fetching';

    public const ANALYSING = 'analysing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $input  what the user entered (names, domain, country, language, brand id)
     * @param  array<string, mixed>|null  $proposal
     * @param  list<string>  $pages  addresses of the pages that were read
     * @param  list<string>  $warnings  codes for what was dropped from the model's reply
     */
    public function __construct(
        public readonly string $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly string $kind,
        public readonly array $input,
        public string $status = self::QUEUED,
        public ?array $proposal = null,
        public array $pages = [],
        public array $warnings = [],
        public ?string $error = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'kind' => $this->kind,
            'input' => $this->input,
            'status' => $this->status,
            'proposal' => $this->proposal,
            'pages' => $this->pages,
            'warnings' => $this->warnings,
            'error' => $this->error,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['tenant_id'],
            $data['user_id'],
            $data['kind'],
            $data['input'],
            $data['status'],
            $data['proposal'],
            $data['pages'],
            $data['warnings'],
            $data['error'],
        );
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::READY, self::FAILED], true);
    }
}
