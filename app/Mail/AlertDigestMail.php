<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily alert digest of one tenant for one recipient (docs/spec.md 11.1). Sent at once from the
 * evaluation job, which already runs in the tenant's context: the mail is not queued separately (a queued
 * mail would be a queued class outside TenantJob, ADR 0005).
 */
class AlertDigestMail extends Mailable
{
    /**
     * @param  list<array{title: string, lines: list<string>}>  $groups
     */
    public function __construct(
        public readonly string $tenantName,
        public readonly array $groups,
        public readonly int $count,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans_choice('alerts.mail.subject', $this->count, ['tenant' => $this->tenantName, 'count' => $this->count]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert-digest');
    }
}
