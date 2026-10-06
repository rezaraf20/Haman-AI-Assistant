<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};
use Illuminate\Queue\SerializesModels;

/** A trial-ending-soon reminder or an already-downgraded notice — see ProcessPlanTransitionsCommand. Reuses VerifyEmail's plain-body view; there is nothing else to a notice this short. */
class TrialNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectText,
        public string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.verify', with: ['body' => $this->bodyText]);
    }
}
