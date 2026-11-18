<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A generated report for one recipient, in the report's own locale (docs/spec.md 11.2). */
class ReportMail extends Mailable
{
    public function __construct(
        public readonly string $brand,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('reports.mail.subject', ['brand' => $this->brand]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.report', with: [
            'safeBrand' => AlertDigestMail::escapeMarkdown($this->brand),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, 'report.pdf')->withMime('application/pdf'),
        ];
    }
}
