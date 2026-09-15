<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};
use Illuminate\Queue\SerializesModels;

/**
 * The address-confirmation link sent after an email signup.
 *
 * A Mailable rather than Mail::raw(): raw() is not intercepted by Mail::fake()
 * — it falls through to the real mailer — so a test could never tell a sent
 * message from a silently swallowed SMTP failure.
 */
class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth_email.verify_subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.verify', with: [
            'body' => __('auth_email.verify_body', [
                'name' => $this->recipientName,
                'link' => $this->link,
            ]),
        ]);
    }
}
