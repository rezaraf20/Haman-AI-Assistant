<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};
use Illuminate\Queue\SerializesModels;

/**
 * Reuses VerifyEmail's own plain-body view on purpose — one proven-correct
 * (2026-10-09 fix: {!! $body !!}, not {{ $body }}) plain-text link
 * template, not a second one that could drift out of sync with it.
 */
class ResetPasswordLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth_email.reset_subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.verify', with: [
            'body' => __('auth_email.reset_body', [
                'name' => $this->recipientName,
                'link' => $this->link,
            ]),
        ]);
    }
}
