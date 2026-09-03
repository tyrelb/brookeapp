<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\TrainingSession;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the client a booked session was cancelled and removes it from their calendar.
 */
class SessionCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public TrainingSession $session,
        public Client $client,
    ) {}

    public function envelope(): Envelope
    {
        $trainer = $this->session->trainer;

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: "Cancelled: training session {$this->session->starts_at->format('D M j, g:i a')} with {$trainer->displayName()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sessions.cancelled',
            with: [
                'session' => $this->session,
                'client' => $this->client,
                'trainer' => $this->session->trainer,
            ],
        );
    }

    public function attachments(): array
    {
        $ics = Ics::forSession($this->session, $this->client, Ics::METHOD_CANCEL);

        return [
            Attachment::fromData(fn () => $ics, 'cancel.ics')
                ->withMime('text/calendar; charset=utf-8; method=CANCEL'),
        ];
    }
}
