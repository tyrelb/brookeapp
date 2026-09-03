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
 * "Your session is booked" (or "updated") with a calendar invite attached.
 */
class SessionBookedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public TrainingSession $session,
        public Client $client,
        public bool $isUpdate = false,
    ) {}

    public function envelope(): Envelope
    {
        $trainer = $this->session->trainer;
        $when = $this->session->starts_at->format('D M j, g:i a');

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: ($this->isUpdate ? 'Updated: ' : '')."Training session {$when} with {$trainer->displayName()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sessions.booked',
            with: [
                'session' => $this->session,
                'client' => $this->client,
                'trainer' => $this->session->trainer,
                'isUpdate' => $this->isUpdate,
                'others' => $this->session->attendees->pluck('client')->reject(fn ($c) => $c->is($this->client))->pluck('full_name'),
            ],
        );
    }

    public function attachments(): array
    {
        $ics = Ics::forSession($this->session, $this->client, Ics::METHOD_REQUEST);

        return [
            Attachment::fromData(fn () => $ics, 'invite.ics')
                ->withMime('text/calendar; charset=utf-8; method=REQUEST'),
        ];
    }
}
