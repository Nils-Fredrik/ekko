<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the old address when a user changes the e-mail address of the account, so the owner of the
 * account learns about a change they did not make (T39-F4). Sent at once, in the request's locale.
 */
class EmailAddressChangedMail extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth.email_changed.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.email-address-changed', with: [
            'safeName' => AlertDigestMail::escapeMarkdown($this->name),
            'safeEmail' => AlertDigestMail::escapeMarkdown($this->newEmail),
        ]);
    }
}
